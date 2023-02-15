<?php
declare(strict_types=1);

namespace Airborne;

/**
 * Tilda's webhooks processing
 */
class TildaProcessor
{
    public Logger $logger;
    public Logger $badLogger;
    private PDO_PG $dbase;
    private DuplicateProcessor $merger;

    public function __construct()
    {
        $this->merger = new DuplicateProcessor();
        $this->dbase = new PDO_PG();
        $this->logger = new Logger(__DIR__ .'/../../logs/'.basename(__FILE__,'.php').'.log');
        $this->badLogger = new Logger(__DIR__ .'/../../logs/'.basename(__FILE__,'.php').'Errors.log');
        $field_array = array_map(function($field){ return ['name'=>$field['name'], 'type'=>$field['type']]; },TILDA_FIELDS_ARRAY);
        $this->dbase->create_table(PGDB['schema'], TILDA_TABLE, $field_array, ['PRIMARY KEY (id)']);
    }

    /**
     * @param array $input
     * @return bool
     */
    public function process(array $input):bool
    {
        $tilda_data = $this->validation($input);
        if (!$tilda_data['phone']) {
            $this->badLogger->log('Wrong phone number: '.$input['phone']);
            return false;
        }
        $fields_array = array_slice(array_column(TILDA_FIELDS_ARRAY,'name'),1);
        $this->dbase->insert_into_table(PGDB['schema'].'.'.TILDA_TABLE, [$tilda_data], $fields_array);
        $tilda_data = $this->dbContactCheck($tilda_data);
        if (!count($tilda_data)) return false;
        if (!$tilda_data['contact_id']) {
            $tilda_data = $this->contactCreating($tilda_data);
            if (!count($tilda_data)) return false;
        }
        if (!$tilda_data['lead_id']){
            $result = $this->leadCreating($tilda_data);
            if (!count($result)){
                $this->badLogger->log('Cannot create leads, terminating');
                return false;
            }
        } else {
            $result = $this->taskAndNote($tilda_data);
            return !!count($result['task']) && !!count($result['note']);
        }
        return true;
    }

    /**
     * @param array $tilda_data
     * @return array
     */
    private function taskAndNote(array $tilda_data): array
    {
        $task_body = [
            'responsible_user_id'=>$tilda_data['responsible_user_id'],
            'entity_id'=>$tilda_data['lead_id'],
            'entity_type'=>'leads',
            'text'=>TILDA_NOTICE,
            'complete_till'=>strtotime('Tomorrow')-3*60*60-60,
        ];
        $result['task'] = $this->merger->creatingTasks([$task_body])[0] ?? [];
        if (!count($result['task'])){
            $this->badLogger->log('Cannot create task, data: '.json_encode($tilda_data));
        }
        $tilda_text = '';
        foreach($tilda_data as $field=>$value) { $tilda_text .= $field . ' : ' .$value . PHP_EOL; }
        $note_body = [
            'element_id'=>$tilda_data['lead_id'],
            'note_type'=>'common',
            'text'=> TILDA_NOTICE . PHP_EOL . $tilda_text,
            'element_type'=>'leads'
        ];
        $result['note'] = $this->merger->makeNotes([$note_body])[0] ?? [];
        if (!count($result['note'])){
            $this->badLogger->log('Cannot create note, data: '.json_encode($tilda_data));
        }
        return $result;
    }

    /**
     * @param array $tilda_data
     * @return array
     */
    private function leadCreating(array $tilda_data): array
    {
        $custom_fields = array_slice(TILDA_FIELDS_ARRAY,4);
        $lead_model = [
            'name'=>$tilda_data['name'],
            'responsible_user_id'=>$tilda_data['responsible_user_id'],
            'status_id'=>OTHER_SOURCES_PIPELINE_STATUSES[0],
            'pipeline_id'=>OTHER_SOURCES_PIPELINE,
            '_embedded'=>[
                'contacts'=>[['id'=>(int)$tilda_data['contact_id']]],
                'tags'=>[TILDA_LEAD_TAG]
            ],
            'custom_fields_values' => [
                ['field_id'=>SOURCE_FIELD, 'values'=>[['enum_id'=>TILDA_SOURCE_ID]]]
            ]
        ];
        $lead_model['custom_fields_values'][] = ['field_id'=>685631, 'values'=>[['enum_id'=>832047]]];
        array_walk($custom_fields, function($field) use (&$lead_model, $tilda_data){
            if (isset($tilda_data[$field['name']])){
                $lead_model['custom_fields_values'][] = ['field_id'=>$field['id'],'values'=>[['value'=>$tilda_data[$field['name']]]]];
            }
        });
        return $this->merger->creatingLeads([$lead_model]);
    }

    /**
     * @param array $tilda_data
     * @return array
     */
    private function contactCreating(array $tilda_data): array
    {
        $request_body[] = [
            'name'=>$tilda_data['name'],
            'responsible_user_id'=>TILDA_MAIN_RESPONSIBLE,
            'custom_fields_values'=>[['field_id' => PHONE_FIELD_ID,'values'=>[['value'=>$tilda_data['phone']]]]],
            '_embedded'=>['tags'=>[TILDA_CONTACT_TAG]]
        ];
        $result = $this->merger->creatingContacts($request_body);
        if (!count($result)) {
            $this->badLogger->log('Cannot create contact for the records, terminating');
            return [];
        }
        $tilda_data['contact_id'] = $result[0]['id'];
        $tilda_data['responsible_user_id'] = TILDA_MAIN_RESPONSIBLE;
        return $tilda_data;
    }

    /**
     * @param $tilda_data
     * @return array
     */
    public function dbContactCheck($tilda_data): array
    {
        $tilda_data['contact_id'] = null;
        $tilda_data['lead_id'] = null;
        $contact = $this->dbase->select_from_table(PGDB['schema'].'.'. PHONES_TABLE,[],['phone'=>$tilda_data['phone']])[0] ?? null;
        if ($contact){
            $tilda_data['contact_id'] = (int)$contact['id'];
            $tilda_data['responsible_user_id'] = (int)$contact['responsible_user_id'];
            $linked_leads = json_decode($contact['linked_leads']);
            if (count($linked_leads)){
                $leads = $this->merger->getLeads($linked_leads, ['page'=>1, 'limit'=>AMO_MAX_LIMIT]);
                if (!count($leads)) {
                    $this->badLogger->log('Cannot get leads, data: '.json_encode($tilda_data));
                }
                foreach($leads as $current_lead){
                    if (in_array($current_lead['status_id'],[...CALLING_PIPELINE_STATUSES,...OTHER_SOURCES_PIPELINE_STATUSES,...DESIGN_PIPELINE_STATUSES])){
                        $tilda_data['lead_id'] = $current_lead['id'];
                        break;
                    }
                }
            }
        }
        return $tilda_data;
    }

    /**
     * @param $tilda_data
     * @return array
     */
    private function checkingContact($tilda_data): array
    {
        $tilda_data['contact_id'] = null;
        $tilda_data['lead_id'] = null;
        $request_body = ['filter'=>['cf'=>[PHONE_FIELD_ID=>[$tilda_data['phone']]]], 'useFilter'=>'y'];
        $contact = $this->merger->getContactsFront($request_body)['items'][0] ?? [];
        if (count($contact)){
            $tilda_data['contact_id'] = (int)$contact['id'] ?? null;
            if ($contact['leads'] && is_array($contact['leads']) && count($contact['leads'])){
                foreach($contact['leads'] as $contact_lead){
                    if (in_array($contact_lead['STATUS'],array_merge(CALLING_PIPELINE_STATUSES, OTHER_SOURCES_PIPELINE_STATUSES, DESIGN_PIPELINE_STATUSES))){
                        $tilda_data['lead_id'] = (int)$contact_lead['ID'];
                        break;
                    }
                }
            }
        }
        return $tilda_data;
    }

    /**
     * @param array $input
     * @return array
     */
    private function validation(array $input): array
    {
        $tilda_data['received'] = time();
        $tilda_data['name'] = $input['Name'] ?? TILDA_DEFAULT_NAME;
        $tilda_data['phone'] = $this->merger->trimPhone($input['Phone']) ?? null;
        $tilda_data['tranid'] = $input['tranid'] ?? null;
        $tilda_data['formid'] = $input['formid'] ?? null;
        if (isset($input['COOKIES']) && is_string($input['COOKIES']) && strlen($input['COOKIES'])){
            $cookie_array = explode(';',$input['COOKIES']);
            if (count($cookie_array)){
                $cookies = [];
                array_walk($cookie_array, function($cookie) use (&$cookies){
                    $cookie = trim(strtolower(htmlspecialchars_decode($cookie)));
                    $cookie_array = explode('=', $cookie);
                    if (count($cookie_array)) {
                        $cookies[$cookie_array[0]] = $cookie_array[1];
                    }
                });
                foreach(TILDA_COOKIES_FIELDS as $field){
                    $tilda_data[$field] = $cookies[$field] ?? null;
                }
            }
        }
        if (isset($tilda_data['previousurl'])){
            $tilda_data['previousurl'] = 'https://'.preg_replace('/%2f/','',$tilda_data['previousurl']);
        }
        return $tilda_data;
    }
}