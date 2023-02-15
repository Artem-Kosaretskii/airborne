<?php
declare(strict_types=1);

namespace Airborne;

use AmoCRM\Filters\LeadsFilter;
use AmoCRM\Collections\NotesCollection;
use AmoCRM\Exceptions\AmoCRMApiException;
use AmoCRM\Collections\Leads\LeadsCollection;
use AmoCRM\Exceptions\AmoCRMoAuthApiException;
use AmoCRM\Exceptions\InvalidArgumentException;
use AmoCRM\Exceptions\AmoCRMMissedTokenException;

class ScaleProcessor
{

private Authorization $client;
public Logger $badlog;
public Logger $log;
private PDO_PG $db;
private string $table;
private string $schema;
private array $processed;
private int $currentTime;
private array $emptyLeads;

public function __construct()
{
    $this->db = new PDO_PG();
    $this->log = new Logger(__DIR__ .'/../../logs/'.basename(__FILE__,'.php').'.log');
    $this->badlog = new Logger(__DIR__ .'/../../logs/'.basename(__FILE__,'.php').'Errors'.'.log');
    $this->currentTime = time();
    $this->client = new Authorization();
    list($this->schema, $this->table) = [PGDB['schema'],MODULE_TABLE];
    $fields_array = MODULE_FIELDS_ARRAY;
    $keys_array = ['PRIMARY KEY (id)'];
    $table_check = $this->db->create_table($this->schema, $this->table, $fields_array, $keys_array);
    // $table_check ? $this->log->log('The table check has been a success') : $this->log->log('Got an error creating module table');
}

    /**
     * @return array
     */
    public function process(): array
{
    $this->processed = [];
    $from = $_POST['from'] ?? ''; //  a string with format 'yyyy-MM-dd'
    if ($from && !$this->validating_date($from)) { $this->badlog->log('An invalid date in the $_POST["from"]: '. $from); die(); }
    $fields_names = array_column(MODULE_FIELDS_ARRAY,'name');

    // Update DB with new records from MB
    // $this->log->log('Renewing data in the module table');
    $this->processed['there_is_new_records'] = $this->update_module($this->schema.'.'.$this->table, $this->currentTime, $fields_names, $from);

    //Creating notes
    $this->processed['new_notes_are_created'] = $this->creatingNotes();

    //Moving leads;
    $this->processed['leads_are_moved'] = $this->moveLeads();

    return $this->processed;
}

private function validating_date($date): bool
{
    return !((strtotime($date) < strtotime(START_DATE) || strtotime($date) > time()));
}

private function preparing_module_data(array $data, int $time): array
{
    $values = [];
    array_walk($data, function($data_item, $data_key) use($time, &$values){
        $values[$data_key]['id'] = $data_item['id'];
        $values[$data_key]['inn'] = $data_item['contragentInn'] ?? null;
        $values[$data_key]['kpp'] = $data_item['contragentKpp'] ?? null;
        $values[$data_key]['bic'] = $data_item['contragentBankBic'];
        $values[$data_key]['name'] = $data_item['contragentName'];
        $values[$data_key]['amount'] = $data_item['amount'];
        $values[$data_key]['status'] = $data_item['status'];
        $values[$data_key]['account'] = $data_item['bankAccountNumber'];
        $values[$data_key]['card_id'] = $data_item['cardId'] ?? null;
        $values[$data_key]['purpose'] = $data_item['paymentPurpose'] ?? null;
        $values[$data_key]['created'] = date('Y-m-d H:i:s',strtotime($data_item['created']));
        $values[$data_key]['contract'] = $this->get_contract_number($data_item['paymentPurpose']) ?? null;
        $values[$data_key]['currency'] = $data_item['currency'] ?? null;
        $values[$data_key]['executed'] = date('Y-m-d H:i:s',strtotime($data_item['executed']));
        $values[$data_key]['category'] = $data_item['category'];
        $values[$data_key]['bank_name'] = $data_item['contragentBankName'];
        $values[$data_key]['company_id'] = $data_item['companyId'];
        $values[$data_key]['doc_number'] = $data_item['docNumber'] ?? null;
        $values[$data_key]['access_time'] = date('Y-m-d H:i:s',$time);
        $values[$data_key]['cntr_account'] = $data_item['contragentBankAccountNumber'];
        $values[$data_key]['cntr_corr_account'] = $data_item['contragentBankCorrAccount'];
        $values[$data_key]['lead_id'] = null;
        if ($values[$data_key]['contract']){
            $select = $this->db->select_from_table($this->schema.'.'.$this->table, ['lead_id'], ['contract'=>$values[$data_key]['contract']]);
            if (count($select)) {
                $values[$data_key]['lead_id'] = $select[0]['lead_id'] ?? null;
                if ($values[$data_key]['contract'] === '999'){ $values[$data_key] = $this->crutches($values[$data_key]); }
            }
        }
    });
    return $values;
}

private function crutches(array $values): array
{
    mb_regex_encoding("UTF-8");
    mb_ereg_search_init($values['name']);
    if (mb_ereg_search('(Client|CLIENT|client)')) {
        $values['lead_id'] = '87631234';
    } else {
        $values['lead_id'] = '43249847';
    }
    return $values;
}

private function update_module(string $table, int $currentTime, array $fields_array, string $from=''): bool
{
    if (!$from){
        $result = $this->db->select_from_table($table, $fields_array, [],'executed', 'DESC', 1);
        if (count($result)) $from = $result[0]['executed'] ?? '';
    }
    $response_array = $this->requestingData('operations',$from, '', 11, [], 'Debet');
    if (!count($response_array)) return false;
    if ($error = $response_array['Error'] ?? null){
        $this->badlog->log('Error: '.$error);
        return false;
    }
    // $this->log->log('A new module data was received successfully');
    if ($from) {
        // $this->log->log('Filtering data');
        $select = $this->db->select_from_table($table, $fields_array, ['executed'=>$from]);
        $response_array = array_udiff($response_array, $select, function($main, $check){
            return strcmp($main['id'],$check['id']);
        });
    }
    if (!count($response_array)) {
        // $this->log->log('New records are not presenting in the received data');
        return false;
    }
    // $this->log->log('The number of the new records: '.count($response_array).', performing prepare and insert operations');
    $this->processed['new_records'] = $response_array;
    $values_array = $this->preparing_module_data($response_array, $currentTime);
    $insert  = $this->db->insert_into_table($table, $values_array, $fields_array);
    if ($insert){
        $this->processed['no_contract_and_no_lead_id'] = array_filter($values_array, function($record) {
                if (!$record['contract'] && !$record['lead_id']) {
                    return $record;
                } else {
                    return null;
                }
            });
        $this->emptyLeads = array_filter($values_array, function($record) {
             if ($record['contract'] && !$record['lead_id']) {
                return $record;
             } else {
                return null;
             }
        });
    } else {
        $this->badlog->log('Error while inserting the new data into the table');
        return false;
    }
    if (count($this->processed['no_contract_and_no_lead_id'])) {
        // $this->log->log('WARNING: there are records with an empty contract and an empty lead id: '.json_encode(array_column($this->processed['no_contract_and_no_lead_id'],'id')));
        return true;
    }
    if (!count($this->emptyLeads)) {
        // $this->log->log('There is no records with a filled contract and an empty lead id');
        return true;
    }
    $this->processed['empty_leads'] = $this->emptyLeads;
    $search_contracts = array_column($this->emptyLeads,'contract');
    // $this->log->log('Performing a search in AmoCRM for those new contracts without leads in db: '.json_encode($search_contracts));
    array_walk($this->emptyLeads, function($record, $key) {
        $lead_array = $this->get_lead_by_contract((int)$record['contract']);
        if (count($lead_array)) {
            $lead_id = $lead_array['id'] ?? null;
            $this->emptyLeads[$key]['lead_id'] = $lead_id;
        }
    });
    $this->emptyLeads = array_filter($this->emptyLeads, function($record) { if ($record['lead_id']) { return $record; } else { return null; }});
    if (count($this->emptyLeads)) {
        $_ = $this->db->update_table($table, $this->emptyLeads, 'contract', ['lead_id']);
        return $_;
    } else {
        // $this->log->log('There are no leads with filled contracts in AmoCRM for contracts: '.json_encode($search_contracts));
        return true;
    }
}

    /**
     * @param string $type
     * @param string $from
     * @param string $till
     * @param int $num_iterations
     * @param array $operations
     * @param string $category
     * @return array|string[]
     */
    private function requestingData(string $type = '', string $from='', string $till='', int $num_iterations=0, array $operations=[], string $category='Debet'): array
{
    if ($type === 'operations'){
        list($skip, $body, $response_array) = [0,['category'=>$category,'records'=>MODULE_N_RECORDS],[]];
        list($account_id, $operation_link) = [MODULE_ID,MODULE_OPS];
        if ($from) { $body['from'] = $from; }
        if ($till) { $body['till'] = $till; }
        if (count($operations)) { $body['operations'] = implode(',',$operations); }
        $link = MODULE_URL . $operation_link .'/'.$account_id;
        do {
            $body['skip'] = MODULE_N_RECORDS * $skip;
            $headers = ['Authorization: Bearer '.MODULE_TOKEN, 'Content-Type: application/json', 'Host: api.modulbank.ru', 'Content-Length:'.strlen(json_encode($body))];
            $response = $this->httpsRequest('POST', $link, $headers, $body);
            $batch = json_decode($response,true) ?? null;
            if ($batch && is_array($batch) && count($batch)) {
                $response_array = array_merge($response_array,$batch);
            } else {
                $skip = $num_iterations;
            }
            ++$skip;
        } while ($skip < $num_iterations);
        return $response_array;
    } elseif ($type === 'info') {
        $link = MODULE_URL . MODULE_INFO;
        $headers = ['Authorization: Bearer '.MODULE_TOKEN, 'Content-Type: application/json', 'Host: api.modulbank.ru', 'Content-Length:0'];
        $response = $this->httpsRequest('POST', $link, $headers);
        return json_decode($response,true) ?? [];
    }
    return ['Error'=>'Wrong request type'];
}

private function httpsRequest(string $method, string $link, array $headers, array $body=[], bool $amo_front=false): string
{
    $curl = curl_init();
    count($body) ?
        $amo_front ?
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($body)) :
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body))
        : $body=null;
    curl_setopt($curl, CURLOPT_URL, $link);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_HEADER, 0);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, 60);
    $response = curl_exec($curl);
    // $this->log->log('Code: '.curl_getinfo($curl, CURLINFO_HTTP_CODE).' from request to '.$link);
    curl_close($curl);
    return (string)$response;
}

private function get_contract_number(string $purpose): string|null
{
    mb_regex_encoding("UTF-8");
    mb_ereg_search_init($purpose);
    if (mb_ereg_search(CONTRACT_TMPL)) {
        if ($contract = mb_ereg_search_getregs()[0] ?? null){
            mb_ereg_search_init($contract);
            if (mb_ereg_search('\d+')){
                return mb_ereg_search_getregs()[0] ?? null;
            }
        }
    }
    return null;
}

    /**
     * @param int $lead_id
     * @return string
     */
    public function hookProcessing(int $lead_id): string
{
    $payments = 0;
    if ($lead_id){
        $select = $this->db->select_from_table($this->schema.'.'.$this->table,['amount'],['lead_id'=>(string)$lead_id]);
        if (count($select)) { $payments = array_reduce(array_column($select,'amount'), function($carry, $item) { $carry+=$item; return $carry; }); }
    }
    return json_encode(['payments'=>$payments]);
}

private function fill_contracts(): bool
{
    $records = $this->db->select_from_table($this->schema.'.'.$this->table);
    if (!count($records)) { $this->badlog->log('Empty module table, terminating process'); die(); }
    $opts = [];
    array_walk($records, function($record) use (&$opts) {
        if ($contract_number = $this->get_contract_number($record['purpose']) ?? null) $opts[] = ['id'=>$record['id'], 'contract'=>$contract_number];
    });
    count($opts) ? $result = $this->db->update_table($this->schema.'.'.$this->table, $opts, 'id', ['contract']) : $result = false;
    return $result;
}

private function fill_leads_id():bool
{
    $db_array = $this->db->select_from_table($this->schema.'.'.$this->table);
    $contracts = array_unique(array_column($db_array, 'contract'));
    $contracts = array_filter($contracts, function($item){ if ($item) { return $item; } else { return null; }});
    $contracts = array_map(function($contract){ return ['contract'=>(int)$contract, 'lead_id'=>null]; }, $contracts);
    foreach($contracts as $key=>$contract){
        echo $contract['contract'].' ';
        $lead = $this->get_lead_by_contract($contract['contract']);
        $lead_id = $lead['id'] ?? null;
        if ($lead_id) {
            $contracts[$key]['lead_id'] = (string)$lead_id;
            $contracts[$key]['contract'] = (string)$contract['contract'];
        } else {
            // $this->log->log('There is no lead for contract with number '.$contract['contract']);
        }
    }
    $contracts = array_filter($contracts, function($item){ if ($item['lead_id']) { return $item; } else { return null; }});
    $this->db->update_table($this->schema.'.'.$this->table, $contracts, 'contract', ['lead_id']);
    return true;
}

private function get_lead_by_contract(int $contract_number): array
{
    $body = ['filter'=>['cf'=>[CONTRACT_FIELD=>['from'=>$contract_number, 'to'=>$contract_number]]], 'useFilter'=>'y'];
    $token = $this->client->access_token->getToken();
    if (!$token) die('Cannot get token');
    $link = 'https://'.SUBDOMAIN.'.amocrm.ru/ajax/leads/list/';
    $headers = ['Authorization: Bearer '.$token, 'Accept: application/json, text/javascript, */*; q=0.01', 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With: XMLHttpRequest'];
    $response_array = json_decode($this->httpsRequest('POST', $link, $headers, $body, true),true);
    return $response_array['response']['items'][0] ?? [];
}

private function get_leads(array $params): array
{
    $leads_from_amo = [];
    $client = $this->client->api_client;
    try {
        $lead_service = $client->leads();
    } catch (AmoCRMMissedTokenException $e) {
        die((string)$e);
    }
    $chunked = array_chunk($params['lead_id'], AMO_MAX_LIMIT);
    foreach($chunked as $chunk){
        try {
            //  Doesn't work:
            //$cf_array = [ CONTRACT_FIELD => $contract_number ];
            //$filter->setCustomFieldsValues($cf_array);
            // This work:
            //$lead_id_example = 23804257;
            //$responsible_id_example = 8386705
            //$filter->setResponsibleUserId($responsible_id_example);
            $filter = new LeadsFilter();
            $filter->setPage(1)->setLimit(AMO_MAX_LIMIT)->setIds($chunk);
            $lead = $lead_service->get($filter);
            $leads_from_amo = array_merge($leads_from_amo, ($lead->toArray() ?? []));
        } catch (AmoCRMApiException $e) {
            die('Error while getting lead, '.$e->getMessage());
        }
    }
    return $leads_from_amo;
}

    /**
     * @param array $records
     * @return array
     */
    private function makingAmoNotes(array $records): array
{
    $result_array = [];
    $notes_array = [];
    try {
        $note_service = $this->client->api_client->notes('leads');
        $notes = new NotesCollection();
        $id = 0;
        array_walk($records, function($record) use (&$notes_array, &$id){
            $notes_array[] = [
                'id'=>$id++,
                'entity_id' => (int)$record['lead_id'],
                'created_by'=> MAIN_USER_ID,
                'note_type'=>'common',
                'params'=>[
                    'text'=> PAYMENT_NOTE_TITLE . PHP_EOL.
                        'Сумма: ' .$record['amount']. PHP_EOL.
                        'Назначение: '.$record['purpose'] . PHP_EOL.
                        'Плательщик: '.$record['name'] . PHP_EOL.
                        'Дата поступления: '. date('Y-m-d',strtotime($record['executed'])) . PHP_EOL
                ]
            ];
        });
        $notes_collection = $notes::fromArray($notes_array);
        $result = $note_service->add($notes_collection);
        $result_array = $result->toArray() ?? [];
    } catch (AmoCRMMissedTokenException $e) {
        $this->badlog->log('Error while creating notes service: '.$e);
        die((string)$e);
    } catch (InvalidArgumentException|AmoCRMoAuthApiException|AmoCRMApiException $e) {
        $this->badlog->log('Error while creating notes: '.$e);
    }
    return $result_array;
}

    /**
     * @return array
     */
    private function creatingNotes(): array
{
    $records = $this->db->select_from_table($this->schema.'.'.$this->table,[],['lead_id'=>'IS NOT NULL', 'note_id'=>'IS NULL']);
    $chunked_records = array_chunk($records, NOTES_CHUNK);
    foreach($chunked_records as $chunk){
        $created = $this->makingAmoNotes($chunk);
        if (count($created) && count($created)===count($chunk)) {
            array_walk($created, function($note, $key) use (&$chunk){ $chunk[$key]['note_id'] = (string)$note['id'] ?? null; });
            $chunk = array_filter($chunk, function($record){ if ($record['note_id']) { return $record; } else { return null; }});
            if (count($chunk)) {
                $this->db->update_table($this->schema.'.'.$this->table, $chunk, 'id', ['note_id']);
            }
        }
    }
    return $created ?? [];
}

    /**
     * Updating the status of the leads in amo with changing amount of payments
     * @return array
     */
    private function moveLeads(): array
{
    // $this->log->log('Checking leads for an actual state and moving conditions');
    if (!count($records = $this->db->select_from_table($this->schema.'.'.$this->table,['lead_id, SUM(amount)'],['lead_id'=>'IS NOT NULL'],'','',0,'lead_id'))) return [];
    $records = array_map(function($record){ return ['lead_id'=>(int)$record['lead_id'], 'amount'=>(float)$record['sum']]; },$records);
    $records = array_filter($records, function($record){ if ($record['amount']) { return $record; } else { return null; }});
    $params['lead_id'] = array_column($records, 'lead_id');
    $leads = $this->get_leads($params);
    if (count($leads)) $leads = array_filter($leads, function($lead) { return ($lead['pipeline_id']===PRODUCT_PIPE)&&$lead['price']; });
    $leads_for_moving = [];
    foreach($leads as $key=>$lead){
        $db_lead = array_values(array_filter($records, function($record) use($lead) { return $record['lead_id'] === $lead['id'];}))[0];
        $completion = (int)(100 * $db_lead['amount'] / $lead['price']);
        if ($completion < 20) {
            $actual_state = 1;
        } else if ($completion > 100) {
            $actual_state = 11;
        } else {
            $grade = (int)floor($completion/10);
            $grade%2 ? $actual_state = --$grade : $actual_state = $grade;
        }
        $current_state = array_keys(array_filter(PRODUCT_PIPE_STATUSES,function($status) use ($lead) { return $status['id'] === $lead['status_id']; }))[0];
        $leads[$key]['actual_state'] = $actual_state;
        if ($current_state < $actual_state) {
            $leads_for_moving[] = $leads[$key];
        }
    }
    return count($leads_for_moving) ? $this->update_leads($leads_for_moving) : [];
}

private function update_leads($update_array): array
{
    // $this->log->log('Moving and updating these leads in amoCRM: '.json_encode(array_column($update_array,'id')));
    $updated = [];
    $leads_array = [];
    try {
        $lead_service = $this->client->api_client->leads();
        $leads = new LeadsCollection();
        array_walk($update_array, function($lead) use (&$leads_array){
            $leads_array[] = [
                'id'=>$lead['id'],
                'status_id' => PRODUCT_PIPE_STATUSES[$lead['actual_state']]['id'],
            ];
        });
        $leads_collection = $leads::fromArray($leads_array);
        $result = $lead_service->update($leads_collection);
        $updated = $result->toArray() ?? [];
    } catch (AmoCRMMissedTokenException $e) {
        $this->badlog->log('Error while creating lead service: '.$e);
        die((string)$e);
    } catch (InvalidArgumentException|AmoCRMoAuthApiException|AmoCRMApiException $e) {
        $this->badlog->log('Error while updating leads: '.$e);
    }
    return $updated;
}

}
