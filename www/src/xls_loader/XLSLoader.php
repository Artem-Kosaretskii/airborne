<?php
declare(strict_types=1);

namespace Airborne;

use Airborne\XLSProcessor as XLS;

/**
 * Loads data from an incoming xlsx file
 */
class XLSLoader
{

    private DuplicateProcessor $merger;
    private Authorization $client;
    public  Logger          $log;
    public  Logger          $badlog;
    private PDO_PG $dbase;
    private array $months;
    private array $years;
    private array $sources;
    private array $complexEnums;
    private string $file;
    private string $fileTransformed;

    public function __construct()
{
    $this->file =__DIR__.'/../../data/form-file';
    $this->fileTransformed =__DIR__.'/../../data/transformed';
    $this->merger = new DuplicateProcessor();
    $this->client = new Authorization();
    $this->log =    new Logger(__DIR__ .'/../../logs/'.basename(__FILE__,'.php').'.log');
    $this->badlog = new Logger(__DIR__ .'/../../logs/'.basename(__FILE__,'.php').'Errors.log');
    $this->months = MONTHS;
    $this->years = YEARS;
    $this->sources = SOURCES;
    $this->complexEnums = [];
    $this->dbase = new PDO_PG();
}

/**
 * @param string $input
 * @return void
 */
    public function process(string $input): void
{
    if (strlen($input)) $input_data = $this->parse_raw_http_request($input);
    if (isset($input_data['raw_text'])) {
        file_put_contents($this->file.'.xlsx', $input_data['raw_text']);
        file_put_contents($this->file.'_'.time().'_.xlsx', $input_data['raw_text']);
        // $this->log->log('There is new incoming data in php://input');
    } else {
        $this->log->log('There is no incoming data in php://input');
        exit();
    }
    $this->parseXLS();
    $db_array = $this->getJsonData();
    $db_array = $this->arrayTransform($db_array);
    $this->compareComplexes($db_array);
    $db_array = $this->checkingContacts($db_array);
    $filled_records = $this->filling_records($db_array);
    $new_leads = $this->creating_new_leads($filled_records);
    if (count($new_leads)) { $this->log->log('New leads are created: '.json_encode(array_column($new_leads, 'id'))); }
}

/**
 * @return void
 */
public function loadProcess(): void
{
    $db_array = json_decode(file_get_contents($this->fileTransformed.'.json'), true);
    unlink($this->fileTransformed.'.json');
    $this->compareComplexes($db_array);
    $db_array = $this->checkingContacts($db_array);
    $filled_records = $this->filling_records($db_array);
    $new_leads = $this->creating_new_leads($filled_records);
    if (count($new_leads)) { $this->log->log('New leads are created: '.json_encode(array_column($new_leads, 'id'))); }
}


/**
 * @return void
 */
    public function parseXLS(): void
{
    if (file_exists($this->file.'.xlsx')){
        $xlsx = XLS::parse($this->file.'.xlsx');
        $rows = $xlsx->rows() ?? [];
        if (count($rows)) {
            file_put_contents($this->file.'.json', json_encode($rows, JSON_UNESCAPED_UNICODE));
            // $this->log->log('The data was received from xlsx and transmitted to json');
            unlink($this->file.'.xlsx');
        }
    }
}

/**
 * @return array
 */
    private function getJsonData(): array
{
    if (!file_exists($this->file.'.json')) {
        $this->badlog->log('There is no json file, terminating process');
        exit();
    }
    $db_excel = json_decode(file_get_contents($this->file.'.json')) ?? [];
    if (!count($db_excel)) {
        $this->log->log('Empty json db, terminating process');
        exit();
    }
    $db_array = [];
    $excel_fields = array_splice($db_excel, 0, 1)[0];
    $excel_fields = array_map(function($field){ return trim($field); },$excel_fields);
    $xls_keys = array_intersect(XLS_KEYS, $excel_fields);
    if (count($xls_keys) - count($excel_fields)){
        $this->badlog->log('The keys from the incoming data and the default keys do not match');
    }
    array_walk($xls_keys, function($key_field, $key) use (&$db_array, $excel_fields, $db_excel) {
        $eng_key = XLS_ARRAY_KEYS[$key];
        $xls_key = (int)array_search($key_field, $excel_fields); // the number of the current field in the incoming array
        array_walk($db_excel, function($record, $inner_key) use (&$db_array, $eng_key, $xls_key){
            if (is_string($record[$xls_key])){
                $db_array[$inner_key][$eng_key] = trim($record[$xls_key]); // Now we get the value under the specific number in a record, and this number matches exactly the key in the db_array record
            } else {
                $db_array[$inner_key][$eng_key] = $record[$xls_key];
            }
        });
    });
    return $db_array;
}

/**
 * @param $db_array
 * @return array
 */
private function arrayTransform($db_array): array
{
    // $this->log->log('Checking incoming data array, transforming array');
    $new_db_array = [];
    if (!count($users = $this->merger->getUsers())) { $this->badlog->log('Cannot get users, terminating');  die(); };
    array_walk($db_array, function($record, $key) use (&$new_db_array, $users){
        if (is_string($record['client'])) $record['client'] = trim($record['client']);
        if (!$record['client']) {
            $record['client'] = 'Noname_'.time();
        }
        if (is_string($record['complex'])) $record['complex'] = trim($record['complex']);
        if (!$record['complex']) {
            $record['complex'] = 'Unknown';
        }
        if (!is_string($record['phone'])) $record['phone'] = (string)$record['phone'];
        $record['phone'] = $this->merger->trimPhone($record['phone']);
        if (!$record['phone']) {
            $record['phone'] = null;
        }

        if (isset($record['space'])){
            if (is_string($record['space'])) $record['space'] = trim($record['space']);
            $record['space'] = (float)$record['space'];
        } else {
            $record['space'] = null;
        }

        if (isset($record['housing'])){
            if (is_string($record['housing'])) $record['housing'] = trim($record['housing']);
            //$record['housing'] = (float)$record['housing'];
        } else {
            $record['housing'] = null;
        }

        if (is_string($record['month'])) $record['month'] = trim($record['month']);
        if (!$record['month']) {
            $record['month'] = null;
            $record['year'] = null;
        } else {
            $year = (int)preg_replace('/[^\d]/','',$record['month']) ?? null;
            in_array($year, array_column(YEARS, 'value')) ? $record['year'] = $year : $record['year'] = null;
            $matches = [];
            preg_match('/^\S*/', trim($record['month']), $matches);
            $month = $matches[0] ?? null;
            in_array($month, array_column(MONTHS, 'value')) ? $record['month'] = $month : $record['month'] = null;
        }
        if (!in_array((int)$record['responsible'], array_column($users, 'id'))) {
            $record['responsible'] = MAIN_USER_ID;
        } else {
            $responsible = array_values(array_filter($users, function($user) use ($record) { return $user['id'] === $record['responsible'];}))[0];
            if (!$responsible['rights']['is_active']) {
                $record['responsible'] = MAIN_USER_ID;
            }
        }
        if (is_string($record['source'])) $record['source'] = trim($record['source']);
        if (!in_array($record['source'], array_column(SOURCES, 'value'))) {
            $record['source'] = SOURCES[0]['value'];
        }
        $new_db_array[] = $record;
    });
    if (count($new_db_array)) {
        file_put_contents($this->fileTransformed.'.json', json_encode($new_db_array, JSON_UNESCAPED_UNICODE));
    }
    unlink($this->file.'.json');
    return $new_db_array;
}

    /**
     * @return array
     */
    public function getComplexes(): array
{
    $link = 'https://' . SUBDOMAIN . '.amocrm.ru'.'/api/v4/leads/custom_fields/'.COMPLEX_FIELD;
    $headers = ['Authorization: Bearer ' . $this->client->access_token->getToken(), 'Content-Type: application/json'];
    $response = json_decode($this->merger->makeRequest('GET', $link, $headers, null, true, false),true);
    return $response['enums'] ?? [];
}

    /**
     * @param array $body
     * @return array
     */
    public function setComplexes(array $body): array
{
    $method = 'PATCH';
    $link = 'https://' . SUBDOMAIN . '.amocrm.ru'.'/api/v4/leads/custom_fields/'.COMPLEX_FIELD;
    $headers = ['Authorization: Bearer ' . $this->client->access_token->getToken(), 'Content-Type: application/json'];
    $response = json_decode($this->merger->makeRequest($method, $link, $headers, $body, true, false),true);
    return $response['enums'] ?? [] ;
}

    /**
     * @param array $db_array
     * @return void
     */
    private function compareComplexes(array $db_array): void
{
    // $this->log->log('Comparing complexes');
    $this->complexEnums = $this->getComplexes();
    $previous_amount = count($this->complexEnums);
    if (!$previous_amount) {
        $this->badlog->log('Error while getting fields');
        die();
    };
    // $this->log->log('There were '.$previous_amount.' complexes received');
    $complex_values = array_column($this->complexEnums, 'value');
    $db_complexes = array_unique(array_column($db_array,'complex'));
    $absent_complexes = array_diff($db_complexes, $complex_values);
    if (count($absent_complexes)) {
        // $this->log->log('The new complexes :'.json_encode($absent_complexes, JSON_UNESCAPED_UNICODE));
        $body = [
            'name'=>'Complex',
            'enums'=>$this->complexEnums,
        ];
        array_walk($absent_complexes, function($new_name) use (&$body) {
            $body['enums'][] = ['value' => trim($new_name), 'sort'=> 0];
        });
        $this->complexEnums = $this->setComplexes($body);
        if (count($this->complexEnums) > $previous_amount){
            $this->log->log((count($this->complexEnums)-$previous_amount).' more complexes were added');
        } else {
            $this->badlog->log('Error while adding new complexes, terminating');
            die();
        }
    } else {
        // $this->log->log('The new complexes were not presented in the incoming data array, using the current list');
    }
}

    /**
     * @param $body
     * @return array
     */
    public function getContactsFront($body): array
{
    $link = 'https://'.SUBDOMAIN.'.amocrm.ru/ajax/contacts/list/';
    $headers = ['Authorization: Bearer '.$this->client->access_token->getToken(), 'Accept: application/json, text/javascript, */*; q=0.01', 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With: XMLHttpRequest'];
    $response = json_decode($this->merger->makeRequest('POST', $link, $headers, $body,false, true),true) ?? [];
    return $response['response']['items'] ?? [];
}

    /**
     * @param array $db_array
     * @return array
     */
    private function checkingContacts(array $db_array): array
{
    foreach($db_array as $key=>$record){
        $contact = $this->dbase->select_from_table(PGDB['schema'].'.'. TEST_PHONES_TABLE,[],['phone'=>$record['phone']]);
        if (count($contact)){
            $linked_leads = array_column($contact, 'lead_id');
            $linked_leads = array_filter($linked_leads, function($lead){ return $lead!==null; });
            if (count($linked_leads)){
                unset($db_array[$key]);
                $this->log->log('Excluding contact: the contact with the id '.$contact[0]['id'].' and the phone '.$record['phone'].' has linked leads: '. json_encode($linked_leads));
            } else {
                $db_array[$key]['contact_id'] = (int)$contact[0]['id'];
//                $db_array[$key]['responsible'] = null;
            }
        } else {
            $db_array[$key]['contact_id'] = null;
        }
    }
    return $db_array;
}

private function checking_contacts(array $db_array): array
{
    foreach($db_array as $key=>$record){
        $body = ['filter'=>['cf'=>[PHONE_FIELD_ID=>[$record['phone']]]], 'useFilter'=>'y'];
        echo $key.' ';
        $contact = $this->getContactsFront($body)[0] ?? [];
        if (!count($contact)){
            $db_array[$key]['contact_id'] = null;
        } elseif ($contact['leads'] && is_array($contact['leads']) && count($contact['leads'])){
//                foreach($item['leads'] as $item_lead_key=>$item_lead){
//                    if (in_array($item_lead['STATUS'],CALLING_PIPELINE_STATUSES)){
            unset($db_array[$key]);
            $this->log->log('Excluding contact: the contact with the id '.$contact['id'].' and the phone '.$record['phone'].' has linked leads: '.json_encode(array_column($contact['leads'], 'ID')));
//                        break;
//                    }
//                }
        } else {
            $db_array[$key]['contact_id'] = (int)$contact['id'];
//                $db_array[$key]['responsible'] = null;
        }
    }
    return $db_array;
}

/**
 * @param $params
 * @return array
 */
    public function getContactsV4($params): array
{
    $amo_link = 'https://' . SUBDOMAIN . '.amocrm.ru'.'/api/v4/contacts?'.http_build_query($params);
    $headers = ['Authorization: Bearer ' . $this->client->access_token->getToken(), 'Content-Type: application/json'];
    $response = json_decode($this->merger->makeRequest('GET', $amo_link, $headers, null, true, false),true);
    return $response['_embedded']['contacts'] ?? [];
}

public function creating_contacts($body): array
{
    $amo_link = 'https://' . SUBDOMAIN . '.amocrm.ru'.'/api/v4/contacts';
    $headers = ['Authorization: Bearer ' . $this->client->access_token->getToken(), 'Content-Type: application/json'];
    $response = json_decode($this->merger->makeRequest('POST', $amo_link, $headers, $body, true, false), true);
    return $response['_embedded']['contacts'] ?? [];
}

public function patch_contacts($body): array
{
    list($method, $amo_link) = ['PATCH', 'https://'.BASE_DOMAIN . '/api/v4/contacts'];
    $headers = ['Authorization: Bearer ' . $this->client->access_token->getToken(), 'Content-Type: application/json'];
    $response = json_decode($this->merger->makeRequest($method, $amo_link, $headers, $body, true, false),true);
    return $response['_embedded']['contacts'] ?? [];
}

public function filling_records($db_array): array
{
    $page = 0;
    $old_responsible = array_values(array_filter($db_array, function($record){ return $record['contact_id']; }));
    $no_contact = array_values(array_filter($db_array, function($record){ return !$record['contact_id']; }));
    //Establishing new responsible users for the existing contacts with unfilled responsible users according to the incoming array
    $chunked = array_chunk($old_responsible, 100);
    $all_contacts = [];
    foreach($chunked as $chunk){
        $body = [];
        foreach ($chunk as $record){
            $body[] = ['id'=>$record['contact_id'], 'responsible_user_id'=>$record['responsible']];
        }
        $array = $this->patch_contacts($body);
        if (!count($array)) {
            $this->badlog->log('Cannot patch contacts from the records, terminating');
            //die();
        }
        $all_contacts = array_merge($all_contacts, $array);
    }
    //Creating new contacts for the unrepresented contacts in the records (responsible is in the records)
    $chunked = array_chunk($no_contact, 100);
    $filled_records = [];
    foreach($chunked as $chunk){
        $chunk = array_values($chunk);
        $body = [];
        foreach($chunk as $record){
            $body[] = [
                'name'=>$record['client'],
                'responsible_user_id'=>$record['responsible'],
                'custom_fields_values'=>[['field_id' => PHONE_FIELD_ID,'values'=>[['value'=>$record['phone']]]]],
            ];
        }
        $array = $this->creating_contacts($body);
        if (!count($array)) {
            $this->badlog->log('Cannot create contacts for the records, terminating');
            die();
        }
        foreach($chunk as $record_key=>$record){
            $chunk[$record_key]['contact_id'] = $array[$record_key]['id'];
        }
        $filled_records = array_merge($filled_records, $chunk);
    }
    //Records with filled field
    return array_merge($filled_records, $old_responsible);
}

public function creating_leads($body): array
{
    $link = 'https://' . SUBDOMAIN . '.amocrm.ru'.'/api/v4/leads';
    $headers = ['Authorization: Bearer ' . $this->client->access_token->getToken(), 'Content-Type: application/json'];
    $response = json_decode($this->merger->makeRequest('POST', $link, $headers, $body, true, false),true);
    return $response['_embedded']['leads'] ?? [];
}

private function creating_new_leads($filled_records): array
{
    $created_leads = [];
    $chunked = array_chunk($filled_records, 50);
    foreach($chunked as $chunk){
        $chunk = array_values($chunk);
        $body = [];
        foreach($chunk as $record){
            if (!($record['client'] && $record['responsible']) && $record['contact_id']){
                continue;
            }
            $lead_model = [
                'name'=>$record['client'],
                'responsible_user_id'=>(int)$record['responsible'],
                'status_id'=>CALLING_PIPELINE_STATUSES[0],
                'pipeline_id'=>CALLING_PIPELINE,
                '_embedded'=>['contacts'=>[['id'=>(int)$record['contact_id']]]]
            ];
            if ($record['complex']){
                $complex_value_id = array_values(array_filter($this->complexEnums, function($enum) use ($record) {
                    return $enum['value'] === $record['complex'];
                }))[0]['id'];
                $lead_model['custom_fields_values'][] = ['field_id'=>COMPLEX_FIELD,'values'=>[['enum_id'=>(int)$complex_value_id]]];
            }
            if ($record['year']){
                $year_value_id = array_values(array_filter($this->years, function($year) use ($record) {
                    return $year['value'] === (int)$record['year'];
                }))[0]['id'];
                $lead_model['custom_fields_values'][] = ['field_id'=>YEARS_FIELD, 'values'=>[['enum_id'=>(int)$year_value_id]]];
            }
            if ($record['month']){
                $month_value_id = array_values(array_filter($this->months, function($month) use ($record) {
                    return $month['value'] === $record['month'];
                }))[0]['id'];
                $lead_model['custom_fields_values'][] = ['field_id'=>MONTHS_FIELD, 'values'=>[['enum_id'=>(int)$month_value_id]]];
            }
            if ($record['source']){
                $source_value_id = array_values(array_filter($this->sources, function($source) use ($record) {
                    return $source['value'] === $record['source'];
                }))[0]['id'];
                $lead_model['custom_fields_values'][] = ['field_id'=>SOURCE_FIELD, 'values'=>[['enum_id'=>(int)$source_value_id]]];
            }
            if ($record['space']){
                $lead_model['custom_fields_values'][] = ['field_id'=>SPACE_FIELD, 'values'=>[['value'=>$record['space']]]];
            }
            if ($record['housing']){
                $lead_model['custom_fields_values'][] = ['field_id'=>HOUSING_FIELD, 'values'=>[['value'=>$record['housing']]]];
            }
            $body[] = $lead_model;
        }
        $array = $this->creating_leads($body);
        if (!count($array)){
            $this->badlog->log('Cannot create leads, terminating');
            //die();
        }
        $created_leads = array_merge($created_leads, $array);
    }
    return $created_leads;
}

public function parse_raw_http_request(string $input): array
{
    $a_data = [];
    preg_match('/boundary=(.*)$/', $_SERVER['CONTENT_TYPE'], $matches);
    $boundary = $matches[1];
    $a_blocks = preg_split("/-+$boundary/", $input);
    array_pop($a_blocks);
    foreach ($a_blocks as $id => $block)
    {
        if (empty($block)) continue;
        if (str_contains($block, 'application/octet-stream'))
        {
            // match "name", then everything after "stream" (optional) except for prepending newlines
            preg_match('/name=\"([^\"]*)\".*stream[\n|\r]+([^\n\r].*)?$/s', $block, $matches);
        }
        // parse all other fields
        else {
            // match "name" and optional value in between newline sequences
            preg_match('/name=\"([^\"]*)\"[\n|\r]+([^\n\r].*)?\r$/s', $block, $matches);
        }
        if (!in_array($matches[1], ['email','file_type','session_id','session_token']) && str_contains($matches[2], 'Content-Type')){
            $raw_text = preg_replace('/^Content-Type:\s\S*\s*/', '', $matches[2]);
            $a_data['raw_text'] = $raw_text;
        } else {
            $a_data[$matches[1]] = $matches[2];
        }
    }
    return $a_data;
}

private function get_custom_fields_from_request($client, $merger): array
{
    $fields = [];
    $types = ['leads'];
    array_walk($types, function($type) use ($client, $merger, &$fields) {
        $fields[$type] = [];
        $headers = ['Authorization: Bearer ' . $client->access_token->getToken(), 'Content-Type: application/json'];
        $page = 0;
        $page_fields = ['true'];
        $params = ['limit' => 50];
        while(count($page_fields)){
            $params['page'] = ++$page;
            $link = 'https://' . SUBDOMAIN . '.amocrm.ru'.'/api/v4/'.$type.'/custom_fields?'.http_build_query($params);
            $response = $merger->makeRequest('GET', $link, $headers, null, false, true);
            $page_fields = json_decode($response,true)['_embedded']['custom_fields'] ?? [];
            $fields[$type] = array_merge($fields[$type], $page_fields);
        }
    });
    return $fields;
}
}