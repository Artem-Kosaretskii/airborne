<?php
declare(strict_types=1);

namespace Airborne;

use Airborne\CommonLibrary as CL;

/**
 *
 */
class UISController
{
    private PDO_PG $database;
    public  Logger $logger;
    public  Logger $badlog;
    public  Cache $cache;
    private HttpClient $httpClient;
    private DuplicateProcessor $merger;
    private array $webhookColumns;
    private array $incomingColumns;
    private Authorization $client;

    public function __construct()
    {
        $this->httpClient = new HttpClient();
        $this->database = new PDO_PG();
        $this->logger = new Logger(__DIR__ . '/../../logs/' . basename(__FILE__, '.php') . '.log');
        $this->badlog = new Logger(__DIR__ . '/../../logs/' . basename(__FILE__, '.php') . 'Errors.log');
        $this->client = new Authorization();
        $this->cache = new Cache();
        $this->merger = new DuplicateProcessor();
        $this->webhookColumns = ['id serial', 'received timestamp', 'webhook json', 'notification_name varchar(20)', 'virtual_phone_number varchar(20)', 'notification_time timestamp',
            'contact_phone_number varchar(20)', 'employee_full_name varchar(100)', 'employee_id int', 'employee_phone_number varchar(20)', 'call_session_id varchar(20)', 'app_id int', 'communication_id varchar(20)',
            'call_source varchar(20)', 'called_phone_number varchar(20)', 'calling_phone_number varchar(20)', 'communication_type varchar(20)', 'cpn_region_id int', 'cpn_region_name varchar(100)',
            'start_time timestamp', 'notification_timestamp int', 'notification_mnemonic varchar(20)', 'direction varchar(10)', 'talk_time_duration int', 'total_time_duration int',
            'wait_time_duration int', 'employee_ids varchar(100)', 'scenario_name varchar(100)', 'scenario_id int'
        ];
        $this->incomingColumns = ['id serial', 'cdr_id varchar(256)','start_time varchar(256)', 'input_result varchar(256)', 'numa varchar(20)', 'numb varchar(20)', 'responsible_user_id int',
            'direction varchar(10)', 'call_session_id varchar(20)', 'new_phone boolean', 'actual_lead int', 'actual_contact int'];
        $this->dbInit();
    }

    /**
     * @return void
     */
    private function dbInit(): void
    {
        $sql_array = [
            'CREATE TABLE IF NOT EXISTS ' . UIS_WEBHOOKS_TABLE . ' ('.implode(', ', $this->webhookColumns) .', PRIMARY KEY (id));',
            'CREATE TABLE IF NOT EXISTS ' . UIS_INCOMING_TABLE . ' ('.implode(', ', $this->incomingColumns) .', PRIMARY KEY (id));',
        ];
        array_walk($sql_array, function ($sql) {
            $this->database->prepare($sql)->execute();
        });
    }

    /**
     * @return string
     */
    private function getToken(): string
    {
        $token = $this->client->access_token->getToken();
        if (!$token) $this->logger->log('Не удалось получить токен');
        return $token;
    }

    /**
     * @param $collection
     * @return array
     */
    public function createContacts($collection): array
    {
        return json_decode($this->httpClient->makeRequest('POST', 'https://' . SUBDOMAIN . '.amocrm.ru/api/v4/contacts',
            ['Authorization: Bearer '.$this->getToken(), 'Content-Type: application/json'], $collection),true)['_embedded']['contacts'] ?? [];
    }

    /**
     * @param array $input
     * @return bool
     */
    public function processHook(array $input): bool
    {
        $current_lead_id = null;
        $contact_id = null;
        $new_lead = null;
        $user_id = null;
        $columns = array_slice($this->webhookColumns, 1);
        $fields = [];
        array_walk($columns, function($column) use (&$fields, $input){
            $field = explode(' ',$column)[0];
            $fields[] = $field;
        });
        $input['webhook'] = json_encode($input,JSON_UNESCAPED_UNICODE);
        $input['received'] = date('Y-m-d H:i:s',time());
        $this->database->insert_into_table(PGDB['schema'].'.'.UIS_WEBHOOKS_TABLE, [$input], $fields);

        if (!($alias = $input['notification_name'] ?? null)) {
            // $this->logger->log('notification_name is absent');
            return false;
        }
        if (!(in_array($alias, UIS_EVENTS))) {
            // $this->logger->log('notification_name is unknown: '.$alias);
            return false;
        }
        if ($alias !== 'call_ending'){ return true; }

        list($virtual_phone, $calling_phone) = [CL::trimPhone($input['virtual_phone_number']), CL::trimPhone($input['contact_phone_number'])];
        $call_session_id = (string)($input['call_session_id'] ?? null);

        if ($input['direction'] === 'in'){
            //The initial search for the responsible user in the calling table
            if ($call_session_id) {
                // $this->logger->log('Call_session_id: '.$call_session_id);
                $statement = $this->database->prepare('SELECT responsible_user_id, actual_lead, actual_contact FROM '.UIS_INCOMING_TABLE.' WHERE call_session_id = :call_session_id;');
                $statement->execute([':call_session_id'=>$call_session_id]);
                if (count($records = $statement->fetchAll())) {
                    $user_id = $records[0]['responsible_user_id'];
                    $current_lead_id = (int)$records[0]['actual_lead'] ?? null;
                    $contact_id = (int)$records[0]['actual_contact'] ?? null;
                    // $this->logger->log('The actual_lead: '.$current_lead_id);
                    // $this->logger->log('The actual_contact: '.$contact_id);
                }
            } elseif (isset(UIS_PHONES[$virtual_phone])) {
                $user_id = UIS_PHONES[$virtual_phone]['id'] ?? UIS_MAIN_USER_ID;
            } else {
                // $this->logger->log('Virtual phone is unknown: '.$virtual_phone);
                $user_id = UIS_MAIN_USER_ID;
            }
            if (!$contact_id){
                //Searching for the contact
                $statement = $this->database->prepare('SELECT * FROM '.TEST_PHONES_TABLE.' WHERE phone = :phone;');
                $statement->execute([':phone'=>$calling_phone]);
                $records = $statement->fetchAll();
                if (count($records)) {
                    $contact_id = (int)$records[0]['id'];
                    // $this->logger->log('The actual_contact: '.$contact_id);
                }
            }
        } else {
            //Outgoing call - setting the user (calling now in first place)
            if (isset(UIS_PHONES[$virtual_phone])) {
                $user_id = UIS_PHONES[$virtual_phone]['id'] ?? UIS_MAIN_USER_ID;
            } else {
                // $this->logger->log('Virtual phone is unknown: '.$virtual_phone);
                $user_id = UIS_MAIN_USER_ID;
            }

            //Searching for the lead - it mustn't be at 142-143 status, more preferable if it is in the calling pipelines, rather than in other pipelines
            $statement = $this->database->prepare('SELECT * FROM '.LEADS_TABLE.' WHERE id IN (SELECT lead_id FROM '.TEST_PHONES_TABLE.' WHERE phone = :phone)');
            $statement->execute([':phone'=>$calling_phone]);
            $records = $statement->fetchAll();
            $records = array_filter($records, function($record){ return !in_array((int)$record['status_id'],[142,143]); });
            $actual_lead = null;
            if (count($records)){
                foreach($records as $record){
                    if (in_array((int)$record['pipeline_id'],[CALLING_PIPELINE,OTHER_SOURCES_PIPELINE])){
                        $actual_lead = $record;
                        $current_lead_id = $record['id'];
                        // $this->logger->log('The actual_lead: '.$current_lead_id);
                        break;
                    }
                }
                if (!$actual_lead) {
                    $actual_lead = $records[0];
                    $current_lead_id = $actual_lead['id'];
                    // $this->logger->log('The actual_lead: '.$current_lead_id);
                }
            }//Now we've got the actual lead which to place the call at
            //If there is no current lead (because the lead may be at 142-143 status or calling number may be new), we need to search the contact
            //if (!($current_lead_id)){
                //Searching for the contact
            $statement = $this->database->prepare('SELECT * FROM '.TEST_PHONES_TABLE.' WHERE phone = :phone;');
            $statement->execute([':phone'=>$calling_phone]);
            $records = $statement->fetchAll();
            if (count($records)) {
                $contact_id = (int)$records[0]['id'];
                // $this->logger->log('The actual_contact: '.$contact_id);
            }
            //}
        }

        //Creating a contact, if not exists
        if (!$contact_id){
            $model = [
                'name'=>'Новый контакт '.$calling_phone.', '.($input['direction'] === 'in' ? 'входящий' : 'исходящий').' звонок на '.$virtual_phone,
                'responsible_user_id'=>(int)$user_id,
                'custom_fields_values'=>[['field_id'=>PHONE_FIELD_ID, 'values'=>[['value'=>$calling_phone, 'enum_code'=>'MOB']]]]
            ];
            if ($new_contact = $this->merger->creatingContacts([$model])[0] ?? null) $contact_id = (int)$new_contact['id'];
        }

        //Creating a lead, if not exists
        if (!$current_lead_id){
            $model = [
                'name'=>'Новая сделка по звонку '.($input['direction'] === 'in' ? 'с телефона ' : 'на телефон ').$calling_phone,
                'responsible_user_id'=>(int)$user_id,
                'pipeline_id'=>OTHER_SOURCES_PIPELINE,
                'status'=>OTHER_SOURCES_PIPELINE_STATUSES[0],
                '_embedded'=>['contacts'=>[['id'=>$contact_id]]]
            ];
            if ($new_lead = $this->merger->creatingLeads([$model])[0] ?? null) {
                $current_lead_id = (int)$new_lead['id'];
                $time_now = time();
                $this->database->replaceIntoLeads(PGDB['schema'].'.'.LEADS_TABLE,
                    [['id'=>$current_lead_id,'last_mod'=>$time_now,'last_time'=>$time_now,'responsible_user_id'=>(int)$user_id,'status_id'=>OTHER_SOURCES_PIPELINE_STATUSES[0],'pipeline_id'=>OTHER_SOURCES_PIPELINE]],
                    ['id','last_mod','last_time','responsible_user_id','status_id','pipeline_id']);
                $this->database->replaceIntoTestTable(TEST_PHONES_TABLE,
                    [['rec_id'=>hash('md2',$contact_id.$calling_phone.$current_lead_id), 'id'=>$contact_id, 'phone'=>$calling_phone,
                        'last_mod'=>$time_now, 'responsible_user_id'=>(int)$user_id, 'lead_id'=>$current_lead_id]],
                        ['rec_id', 'id', 'phone', 'last_mod', 'responsible_user_id', 'lead_id', 'last_time'], 'phone', $time_now);
            }
        }

        //Creating a note in the lead with the link to the record
        list ($call_status, $record_link) = [6, ''];
        if ($input['direction'] === 'in') { $note_type = 'call_in'; } else { $note_type = 'call_out'; }

        //Retrieving the link for the call record from UIS, if there were a talk
        if ($talk_time = (int)($input['talk_time_duration'] ?? null)) {
            if ($date_till = $input['notification_time'] ?? null) {
                $date_till = date('Y-m-d H:i:s',strtotime($date_till)+10);
            } else {
                $date_till = $input['received'];
            }
            $total_time = (int)($input['total_time_duration'] ?? 3600);
            $date_from = date('Y-m-d H:i:s',strtotime($input['notification_time'])-$total_time-10);
            $request_body = $this->getUISBody('get.calls_report',['date_from'=>$date_from,'date_till'=>$date_till,'access_token'=>UIS_KEY]);
            $sessions = $this->getCallsReport($request_body)['result']['data'] ?? [];
            if (count($sessions = array_filter($sessions, function($session) use ($call_session_id) { return (int)$session['id'] === (int)$call_session_id; }))) {
                if ($record_id = $sessions[0]['call_records'][0] ?? null) {
                    $record_link = 'https://media.uiscom.ru/'.$call_session_id.'/'.$record_id;
                    $call_status = 4;
                }
            }
        }
        $entities_type = 'leads';
        $note_body = [ 'entity_id'=>$current_lead_id, 'created_by'=>$user_id, 'note_type'=>$note_type, 'params'=>[
            'uniq'=>$call_session_id.'_'.$call_session_id,'duration'=>$talk_time ?? 0,'source'=>'UIS','link'=>$record_link,'phone'=>$calling_phone ?? '','call_status'=>$call_status],
        ];
        // $this->logger->log('Note body: '.json_encode($note_body));
        $result = $this->makeNotesV4($entities_type, [$note_body])[0] ?? [];
        if (!count($result)){ $this->badlog->log('Cannot create the note for the '.$entities_type.' '.$current_lead_id); }

        //Creating a task in the lead, if there is an old lead and input call (in new leads a new task is created automatically)
        if (!$new_lead && $input['direction'] === 'in'){
            $talk_time ? $task_text = UIS_NOTICE : $task_text = UIS_MISSED;
            $task_body = [
                'responsible_user_id'=>$user_id,
                'entity_id'=>$current_lead_id,
                'entity_type'=>'leads',
                'text'=> $task_text . $calling_phone,
                //'complete_till'=>strtotime('Tomorrow')-3*60*60-60,
                'complete_till'=>time(),
            ];
            $result['task'] = $this->merger->creatingTasks([$task_body])[0] ?? [];
            if (!count($result['task'])){
                $this->badlog->log('Cannot create task for lead '.$current_lead_id);
            }
        }

        return true;
    }

    /**
     * @param string $method
     * @param array $params
     * @return array
     */
    private function getUISBody(string $method, array $params): array { return ['jsonrpc'=>'2.0','id'=>'1','method'=>$method,'params'=>$params]; }

    /**
     * @return bool
     */
    public function process(): bool
    {
//        $result = $this->getMedia(['jsonrpc' => '2.0', 'id' => '1', 'method' => 'get.media_files', 'params' => ['access_token' => UIS_KEY]]);
//        file_put_contents(__DIR__.'/../../data/uis_media.json',json_encode($result));
        return true;
    }

    /**
     * @param string $entities_type
     * @param array $notes_body
     * @return array
     */
    public function makeNotesV4(string $entities_type, array $notes_body): array
    {
        $response = $this->httpClient->makeRequest('POST', 'https://' . SUBDOMAIN . '.amocrm.ru/api/v4/'.$entities_type.'/notes',
            ['Authorization: Bearer '.$this->getToken(), 'Content-Type: application/json'], $notes_body);
        return json_decode($response,true)['_embedded']['notes'] ?? [];
    }

    /**
     * @param array $body
     * @return array
     */
    public function getInfo(array $body = ['jsonrpc' => '2.0', 'id' => '1', 'method' => 'get.account', 'params' => ['access_token' => UIS_KEY]]): array
    {
        $headers = ['Content-Type: application/json; charset=UTF-8', 'Content-Length: ' . strlen(json_encode($body))];
        $this->httpClient->retry = 0;
        return json_decode($this->httpClient->makeRequest('POST', UIS_LINK, $headers, $body), true) ?? [];
    }

    /**
     * @param array $body
     * @return array
     */
    public function getMedia(array $body): array
    {
        $headers = ['Content-Type: application/json; charset=UTF-8', 'Content-Length: ' . strlen(json_encode($body))];
        $this->httpClient->retry = 0;
        return json_decode($this->httpClient->makeRequest('POST', UIS_LINK, $headers, $body), true) ?? [];
    }

    /**
     * @param string $from
     * @param string $till
     * @return array
     */
    private function getCallsReport(array $request_body): array
    {
        $headers = ['Content-Type: application/json; charset=UTF-8', 'Content-Length: ' . strlen(json_encode($request_body))];
        $this->httpClient->retry = 0;
        return json_decode($this->httpClient->makeRequest('POST', UIS_LINK, $headers, $request_body), true) ?? [];
    }

    /**
     * @param array $hook_data
     * @return string
     */
    public function processRedirect(array $hook_data): string
    {
        $phone_virtual = $hook_data['numb'] ?? null;
        $hook_data['new_phone'] = null;
        if (!$phone_virtual) {
            // $this->logger->log('The virtual number is missing');
            //return '';
        };
        $actual_lead = null;
        $actual_contact = null;

        $phone_virtual = CL::trimPhone((string)$phone_virtual);
        $phone_private = UIS_PHONES[$phone_virtual]['private'] ?? null;

        //Searching for the responsible user for that incoming phone
        if ($hook_data['numa'] ?? null) {
            $hook_data['numa'] = CL::trimPhone($hook_data['numa']);
            // Lead search
            $statement = $this->database->prepare('SELECT * FROM '.LEADS_TABLE.' WHERE id IN (SELECT lead_id FROM '.TEST_PHONES_TABLE.' WHERE phone = :phone)');
            $statement->execute([':phone'=>$hook_data['numa']]);
            $records = $statement->fetchAll();
            if (count($records = array_filter($records, function($record){ return in_array((int)$record['responsible_user_id'], array_column(UIS_PHONES,'id')); }))){
                foreach($records as $record){
                    if ((int)$record['status_id']!==143 && in_array((int)$record['pipeline_id'],[CALLING_PIPELINE,OTHER_SOURCES_PIPELINE])){
                        $actual_lead = $record;
                        $hook_data['responsible_user_id'] = $actual_lead['responsible_user_id'];
                        break;
                    }
                }
                if (!$actual_lead) {
                    $hook_data['responsible_user_id'] = $records[0]['responsible_user_id'];
                    $statement = $this->database->prepare('SELECT * FROM '.TEST_PHONES_TABLE.' WHERE lead_id = :lead_id;');
                    $statement->execute([':lead_id'=>$records[0]['id']]);
                    $records = $statement->fetchAll();
                    if (count($records)) {
                        $actual_contact = $records[0];
                    }
                }
                $phone_private = array_values(array_filter(UIS_PHONES, function($manager) use ($hook_data) {return $manager['id'] === $hook_data['responsible_user_id'];}))[0]['private'];
            } else {
                //Searching for the contact
                $statement = $this->database->prepare('SELECT * FROM '.TEST_PHONES_TABLE.' WHERE phone = :phone;');
                $statement->execute([':phone'=>$hook_data['numa']]);
                $records = $statement->fetchAll();
                if (count($records)) {
                    $filtered  = array_filter($records, function($record){ return in_array((int)$record['responsible_user_id'], array_column(UIS_PHONES,'id')); });
                    if (count($filtered)){
                        $actual_contact = $filtered[0];
                        $hook_data['responsible_user_id'] = $actual_contact['responsible_user_id'];
                        $phone_private = array_values(array_filter(UIS_PHONES, function($manager) use ($hook_data) {return $manager['id'] === $hook_data['responsible_user_id'];}))[0]['private'];
                    } else {
                        $actual_contact = $records[0];
                    }
                }
            }
        } else {
            $hook_data['numa'] = null;
            // $this->logger->log('The calling number is missing');
        }

        //If the calling number is a new number and doesn't have the responsible user
        if (!isset($hook_data['responsible_user_id'])){
            $hook_data['new_phone'] = true;
            if (!$phone_private) {
                // $this->logger->log('The private number for the virtual number '.$phone_virtual.' is undefined');
                //return '';
            //} elseif ($phone_private === 1){
            } else {
                //$query = 'SELECT responsible_user_id as id, COUNT(responsible_user_id) AS count FROM '.UIS_INCOMING_TABLE.' WHERE numb = :numb AND new_phone = true GROUP BY responsible_user_id;';
                $query = 'SELECT responsible_user_id as id, COUNT(responsible_user_id) AS count FROM '.UIS_INCOMING_TABLE.' WHERE new_phone = true GROUP BY responsible_user_id;';
                $statement = $this->database->prepare($query);
                //$statement->execute([':numb' => $phone_virtual]);
                $statement->execute();
                $selected = $statement->fetchAll();
                if (count($selected) > 3){
                    usort($selected, function ($user1, $user2) {
                        if ($user1['count'] === $user2['count']) return 0;
                        return $user1['count'] > $user2['count'] ? 1 : -1;
                    });
                    $phone_private = array_values(array_filter(UIS_PHONES, function($manager) use ($selected) {return $manager['id'] === (int)$selected[0]['id'];}))[0]['private'];
                    $hook_data['responsible_user_id'] = $selected[0]['id'];
                } else {
                    $hook_data['responsible_user_id'] = array_values(array_diff(array_filter(array_column(UIS_PHONES,'id'), function ($item){return !!$item;}),array_column($selected,'id')))[0];
                    $phone_private = array_values(array_filter(UIS_PHONES, function($manager) use ($hook_data) {return $manager['id'] === (int)$hook_data['responsible_user_id'];}))[0]['private'];
                }
            //} else {
                //$hook_data['responsible_user_id'] = UIS_PHONES[$phone_virtual]['id'];
            };
        }
        if (!$phone_private) $phone_private = array_column(UIS_PHONES, 'private')[1];
        if (!$hook_data['responsible_user_id']) $hook_data['responsible_user_id'] = UIS_MAIN_USER_ID;
        $phone_private = '"'.$phone_private.'"';
        $hook_data['numb'] = $phone_virtual;
        $hook_data['actual_lead'] = $actual_lead['id'] ?? null;
        $hook_data['actual_contact'] = $actual_contact['id'] ?? null;
        $hook_data['cdr_id'] = $hook_data['cdr_id'] ?? null;
        $hook_data['start_time'] = $hook_data['start_time'] ?? null;
        $hook_data['input_result'] = $hook_data['input_result'] ?? null;
        $hook_data['direction'] = $hook_data['direction'] ?? null;
        $hook_data['call_session_id'] = $hook_data['call_session_id'] ?? null;
        if ($hook_data['cdr_id']) $hook_data['cdr_id'] = (string)$hook_data['cdr_id'];
        if ($hook_data['start_time']) $hook_data['start_time'] = (string)$hook_data['start_time'];
        if ($hook_data['input_result']) $hook_data['input_result'] = (string)$hook_data['input_result'];
        $this->database->insert_into_table(UIS_INCOMING_TABLE, [$hook_data],
            ['cdr_id','start_time','input_result','numa','numb','responsible_user_id', 'direction', 'call_session_id', 'new_phone', 'actual_lead', 'actual_contact']);
        return '{"phones":['.$phone_private.'], "message_name":"1966.mp3"}';
    }
}