<?php
declare(strict_types=1);

namespace Airborne;

use Exception;
use AmoCRM\Filters\LeadsFilter;
use AmoCRM\Filters\NotesFilter;
use AmoCRM\Collections\NotesCollection;
use AmoCRM\Exceptions\AmoCRMApiException;
use AmoCRM\Collections\Leads\LeadsCollection;
use AmoCRM\Exceptions\AmoCRMoAuthApiException;
use AmoCRM\Exceptions\InvalidArgumentException;
use AmoCRM\Exceptions\AmoCRMMissedTokenException;

class DuplicateProcessor
{

    private PDO_PG $db;
    public  Logger $log;
    public  Logger $badlog;
    public  Cache $cache;
    private Authorization $client;

    private int     $retry;
    public int      $offset;
    private string  $code;
    private string  $token;
    private array   $users;
    public string   $prefix;
    public array    $db_trash;
    private array   $old_notes;
    private array   $cron_opts;
    private array   $db_phones;
    private array   $db_emails;
    private bool    $final_page;
    private array   $duplicates;
    private array   $notes_array;
    private array   $db_contacts;
    private int     $main_duplicate;
    private int     $merge_attempts;
    private int     $response_code;
    private array   $main_contacts;
    private array   $mergedLeads;
    private string  $custom_fields;
    private string  $last_modified;
    public int      $contacts_received;
    private array   $unique_contact_phones;
    private array   $unique_contact_emails;
    private int     $get_merge_info_attempts;
    private int     $contacts_received_total;

//private $with_unsorted;
//private $active_users;
    private array $dbLeads;
    private array $dbTestPhones;
    private array $dbTestEmails;
    private int $startTime;

    public function __construct()
    {
        $this->db = new PDO_PG();
        $this->log = new Logger(__DIR__ . '/../../logs/' . basename(__FILE__, '.php') . '.log');
        $this->badlog = new Logger(__DIR__ . '/../../logs/' . basename(__FILE__, '.php') . 'Errors.log');
        $this->client = new Authorization();
        $this->cache = new Cache();
        $this->prefix = basename(__FILE__, '.php');
        $this->dbInit();
        $this->retry = 0;
        $this->users = [];
        $this->db_trash = [];
        $this->old_notes = [];
        $this->db_phones = [];
        $this->db_emails = [];
        $this->db_contacts = [];
        $this->mergedLeads = [];
        $this->startTime = time();
        $this->main_contacts = [];
        $this->main_duplicate = 0;
        $this->merge_attempts = 0;
        $this->final_page = false;
        $this->contacts_received = 0;
        $this->offset = 0;
        $this->code = 'field_code';
        $this->last_modified = 'updated_at';
        $this->custom_fields = 'custom_fields_values';
        $this->contacts_received_total = 0;

        $this->dbLeads = [];
        $this->dbTestPhones = [];
        $this->dbTestEmails = [];
    }

    /**
     * @return void
     */
    private function dbInit(): void
    {
        $sql_array = [
            'CREATE TABLE IF NOT EXISTS ' . PHONES_TABLE . ' (phone varchar(255), id int, last_mod int, last_time int, responsible_user_id int, linked_leads json DEFAULT NULL, PRIMARY KEY (phone, id));',
            'CREATE TABLE IF NOT EXISTS ' . EMAILS_TABLE . ' (email varchar(255), id int, last_mod int, last_time int, responsible_user_id int, linked_leads json DEFAULT NULL, PRIMARY KEY (email, id));',
            'CREATE TABLE IF NOT EXISTS ' . LEADS_TABLE . ' (id int, last_mod int, last_time int, responsible_user_id int, status_id int, pipeline_id int, PRIMARY KEY (id));',
            'CREATE TABLE IF NOT EXISTS ' . TEST_PHONES_TABLE . ' (rec_id varchar(256), phone varchar(255), id int, last_mod int, last_time int, responsible_user_id int, lead_id int, 
                PRIMARY KEY (rec_id, phone, id),
                FOREIGN KEY (lead_id) REFERENCES ' . LEADS_TABLE . '(id)
                ON DELETE CASCADE
                ON UPDATE CASCADE);',
            'CREATE TABLE IF NOT EXISTS ' . TEST_EMAILS_TABLE . ' (rec_id varchar(256), email varchar(255), id int, last_mod int, last_time int, responsible_user_id int, lead_id int, 
                PRIMARY KEY (rec_id, email, id),
                FOREIGN KEY (lead_id) REFERENCES ' . LEADS_TABLE . '(id)
                ON DELETE CASCADE
                ON UPDATE CASCADE);',
            'CREATE TABLE IF NOT EXISTS ' . DUPLICATES_TABLE . ' (id int NOT NULL, duplicates json NOT NULL, PRIMARY KEY (id));',
            //"CREATE TABLE IF NOT EXISTS ".DUPLICATES_PROXY_TABLE." (id serial NOT NULL, duplicates json NOT NULL, PRIMARY KEY (id))",
            'CREATE TABLE IF NOT EXISTS ' . LOG_TABLE . ' (id int, phones json, emails json, load_time int, 
                    contacts_received int, phones_received int, emails_received int, phones_before int, phones_after int, emails_before int, emails_after int, 
                    PRIMARY KEY (id, load_time));',
            'CREATE TABLE IF NOT EXISTS ' . PROCESSED_CONTACTS_TABLE . ' (id int, PRIMARY KEY (id));',
            'CREATE TABLE IF NOT EXISTS ' . PROCESSED_DUPLICATES_TABLE . ' (id int, PRIMARY KEY (id));',
        ];
        array_walk($sql_array, function ($sql) {
            $this->db->prepare($sql)->execute();
        });
    }

    /**
     * Главная функция по обработке дублей контактов
     * @param array $cron_opts
     * @return void
     * @throws Exception
     */
    public function process(array $cron_opts): void
    {
        $this->cron_opts = $cron_opts;
        $this->token = $this->getToken();
        if (!$this->token) {
            $this->log->log('Токен не получен, завершаем работу');
            return;
        }
        if ($cron_opts['fixing_leads']) { $this->fixingLeads(); return; }
        if ($cron_opts['merge_leads']) { $this->selectContactsFront(); return; }
        if (($cron_opts['new'] || $cron_opts['load'] || ($cron_opts['merge'] && !$cron_opts['continue'])) && !$cron_opts['truncate_before'] && !$cron_opts['truncate_duplicates'] && $this->db->getSize(DUPLICATES_TABLE)) {
            $this->log->log('Предыдущее объединение не завершено, перед обновлением нужно завершить старые задачи или очистить таблицу дублей');
            return;
        }
        // $this->log->log('Размер таблицы ' . TEST_PHONES_TABLE . ' перед началом работы крона: ' . $this->db->getSize(TEST_PHONES_TABLE));
        // $this->log->log('Размер таблицы ' . TEST_EMAILS_TABLE . ' перед началом работы крона: ' . $this->db->getSize(TEST_EMAILS_TABLE));
        if ($cron_opts['truncate_processed']) {
            // $this->log->log('Очистка таблиц с обработанными дублями и контактами');
            $this->db->truncate_processed($this);
        }
        if ($cron_opts['truncate_before'] || $cron_opts['truncate_duplicates']){
            $this->db->truncateData($cron_opts['truncate_duplicates']);
        } else {
            // $this->log->log('Таблицы не очищались перед началом работы крона');
        }
        if (($cron_opts['new'] || $cron_opts['load'] || $cron_opts['merge'] || $cron_opts['duplicates_search'])
            && ($this->db->getSize(PROCESSED_DUPLICATES_TABLE) || $this->db->getSize(PROCESSED_CONTACTS_TABLE)))
            {
                // $this->log->log('Таблицы с обработанными дублями и контактами не очищены, завершение работы');
                die();
            }
        if ($cron_opts['continue']) {
            $this->trash($this->cache->get($this->prefix . '_trash'), $this->db->getLatest([TEST_PHONES_TABLE, TEST_EMAILS_TABLE]), false, $cron_opts['continue']);
        }
        if ($cron_opts['load'] || $cron_opts['new']) {
            if (!($updated_from = $this->db->getLatest([TEST_PHONES_TABLE, TEST_EMAILS_TABLE])) && $cron_opts['new']) {
                // $this->log->log('There is an empty DB, an updating is not available, terminating the process...');
                return;
            }
            $this->trash($this->cache->get($this->prefix . '_trash'), $updated_from, $cron_opts['new']);
            // $this->log->log('The old contacts, which were updated, will be deleted and re-recorded in DB');
            if ($this->cron_opts['page_from']) $this->offset = --$cron_opts['page_from'];
            if ($this->cron_opts['page']) sort($this->cron_opts['page']);
            $exit_time = time() + WORK_MIN * 60;
            while ($this->getNextBatch($cron_opts['new'], $updated_from)) {
                if (time() > $exit_time) {
                    // $this->log->log('The working time limit is over ' . WORK_MIN . ' минут');
                    // $this->log->log('The number of the received contacts: ' . $this->contacts_received_total);
                    break;
                }
            }
            // $this->log->log('The number of the received contacts: ' . $this->contacts_received_total);
            // $this->log->log('Updating leads ... >');
            $this->getLinkedLeads($cron_opts['new'], $updated_from, []);
        }
        if ($cron_opts['duplicates_search']) {
            // $this->log->log('Выбран поиск дублей без объединения');
            if (!$this->db->getSize(TEST_PHONES_TABLE) && (!$this->db->getSize(TEST_EMAILS_TABLE))) {
                // $this->log->log('Нет данных для поиска дублей, выход');
                return;
            }
            $this->log->log('Найдено ' . count($this->db->select_duplicates()) . ' дублей, поиск закончен, завершение работы');
            return;
        }
        if ($cron_opts['merge']) {
            $this->users = array_filter($this->getUsers(), function ($user) { return $user['rights']['is_active'] === true; });
            $this->duplicates = $this->db->select_duplicates($cron_opts['continue']);
            // $this->log->log('Найдено ' . count($this->duplicates) . ' дублей');
            foreach ($this->duplicates as $key => $duplicates) {
                if ($cron_opts['continue']) {
                    $duplicate_db_id = intval($duplicates['id']);
                    $duplicates = array_values(json_decode($duplicates['duplicates'], true));
                } else {
                    $duplicate_db_id = $key;
                }
                // $this->log->log('Дубль # ' . $duplicate_db_id);
                $duplicates = array_map('intval', $duplicates);
                sort($duplicates);
                $limit = MERGE_LIMIT - 1;
                $this->main_duplicate = $duplicates['0'];
                $other_duplicates = array_chunk(array_slice($duplicates, 1), $limit);
                array_walk($other_duplicates, function ($chunk) {
                    if ($this->main_duplicate === 0) $this->main_duplicate = array_shift($chunk); // Если основной контакт оказался с неразобранным
                    array_splice($chunk, 0, 0, $this->main_duplicate);
                    if (count($chunk) > 1) $_ = $this->processDuplicates($chunk); // длина пачки может быть 1 в случае если это последний элемент и основной был перед этим с неразобранным
                });
                if ($this->main_duplicate) $duplicates = array_diff($duplicates, [$this->main_duplicate]);
//                if (count($this->with_unsorted)){
//                    $duplicates = array_diff($duplicates, $this->with_unsorted);
//                    $this->with_unsorted = [];
//                };
                $this->db->replace_into_processed(PROCESSED_DUPLICATES_TABLE, [$duplicate_db_id], ['id']);
                if (count($duplicates)) $this->db->replace_into_processed(PROCESSED_CONTACTS_TABLE, $duplicates, ['id']);
                $this->main_contacts[] = $this->main_duplicate;
                // $this->db->delete_records($duplicates, $duplicate_db_id);
                if (!--$cron_opts['merge']) break;
            }
            $this->db->truncate_processed($this);
        }
        // count($this->main_contacts) ? $this->log->log('Объединенные контакты: ' . json_encode($this->main_contacts)) : $this->log->log('Объединенных контактов нет');
        // $this->log->log('Размер таблицы ' . TEST_PHONES_TABLE . ' после работы крона: ' . $this->db->getSize(TEST_PHONES_TABLE));
        // $this->log->log('Размер таблицы ' . TEST_EMAILS_TABLE . ' после работы крона: ' . $this->db->getSize(TEST_EMAILS_TABLE));
        if ($cron_opts['truncate_after']) {
            $this->db->truncateData();
        } else {
           // $this->log->log('Таблицы c необработанными данными не очищались после работы крона');
        }
    }

    /**
     * @param bool $new
     * @param int $updated_from
     * @param array $total
     * @return void
     */
    private function getLinkedLeads(bool $new, int $updated_from = 0, array $total = []): void
    {
        if ($new){
            // $this->log->log('Updating leads in database, getting updated leads in first place');
            $this->offset = 0;
            do {
                $params = ['filter' => ['updated_at' => ['from' => $updated_from]], 'page' => ++$this->offset, 'limit' => AMO_MAX_LIMIT, 'order' => ['id' => 'asc']];
                $batch = $this->getLeads([], $params);
                $total = [...$total, ...$batch];
            } while (count($batch));
        }
        // $this->log->log('loading new leads');
        $leads = array_column($this->db->select_from_table(PGDB['schema'].'.'.LEADS_TABLE, ['id'], ['status_id'=>'IS NULL']),'id');
        $leads = array_diff($leads, array_column($total, 'id'));
        $chunked = array_chunk($leads, AMO_MAX_LIMIT);
        foreach($chunked as $chunk){ $total = [...$total, ...$this->getLeads($chunk, ['page'=>1, 'limit'=>AMO_MAX_LIMIT])]; }
        $total = array_map(function($lead) { return
            ['id'=>$lead['id'],'last_mod'=>$lead['updated_at'],'last_time'=>$this->startTime,'responsible_user_id'=>$lead['responsible_user_id'],'status_id'=>$lead['status_id'],'pipeline_id'=>$lead['pipeline_id']];}, $total);
        $this->db->replaceIntoLeads(PGDB['schema'].'.'.LEADS_TABLE, $total, ['id','last_mod','last_time','responsible_user_id','status_id','pipeline_id']);
    }

    /**
     * Получение батча контактов, выборка телефонов и почт из контактов и отправка в БД
     * @param bool
     * @param int $updated_from
     * @return bool
     */
    private function getNextBatch(bool $new, int $updated_from = 0): bool
    {
        if ($this->final_page) {
            // $this->log->log('The last batch size: ' . $this->contacts_received);
            return false;
        }
        if ($this->cron_opts['page']) {
            $this->offset = array_shift($this->cron_opts['page']);
            $params = ['page' => $this->offset, 'limit' => AMO_MAX_LIMIT, 'order' => ['id' => 'asc'], 'with'=>'leads'];
            if (!count($this->cron_opts['page'])) $this->final_page = true;
        } else {
            $new ? $params = ['filter' => ['updated_at' => ['from' => $updated_from]], 'page' => ++$this->offset, 'limit' => AMO_MAX_LIMIT, 'order' => ['id' => 'asc'], 'with'=>'leads']
                : $params = ['page' => ++$this->offset, 'limit' => AMO_MAX_LIMIT, 'order' => ['id' => 'asc'], 'with'=>'leads'];
        }
        $current_offset = strval(AMO_MAX_LIMIT * ($this->offset - 1));
        if ($this->cron_opts['page_to'] && $this->offset === $this->cron_opts['page_to']) $this->final_page = true;
        $contacts = $this->getContactsV4($params);
        // count($contacts) ? $this->log->log('OFFSET ' . $current_offset) : $this->log->log('The last contact batch size: ' . $this->contacts_received);
        // echo ' OFFSET ' . $current_offset;
        if (!count($contacts) || !isset($contacts) || !$contacts) return false;
        $time = time();
        $this->contacts_received = count($contacts);
        $this->contacts_received_total += count($contacts);
        $contacts = array_filter($contacts, function ($contact) {
            if (isset($contact[$this->custom_fields])){
                return in_array('PHONE', array_column($contact[$this->custom_fields], $this->code)) || in_array('EMAIL', array_column($contact[$this->custom_fields], $this->code));
            } else {
                return null;
            }
        });
        array_map([$this, 'filter'], $contacts);
        $this->db_contacts = $this->db_phones = $this->db_emails = $this->db->insert_batch($this, $this->db_contacts, $this->db_phones, $this->db_emails, $time, $new);
        $this->dbTestPhones = $this->dbTestEmails = $this->dbLeads = $this->db->insertTestBatch($this, $this->dbTestPhones, $this->dbTestEmails, array_unique($this->dbLeads), $time, $new);
        return true;
    }

    /**
     * Фильтрация контактов на предмет наличия телефонов и почт, подготовка к отправке в БД
     * @param array $contact
     * @return void
     */
    private function filter(array $contact): void
    {
        $custom_fields = $this->custom_fields;
        $code = $this->code;
        $last_modified = $this->last_modified;
        list($linked_leads,$phone_values_unique,$email_values) = [[null],null,null];
        if (isset($contact['_embedded']['leads']) && count($contact['_embedded']['leads'])){
            $linked_leads = array_column($contact['_embedded']['leads'], 'id');
        }
        $phone_cell = array_filter($contact[$custom_fields], function ($cf) use ($code) {
            if (in_array($code, array_keys($cf))) {
                return $cf[$code] === 'PHONE';
            } else { return null; }
        });
        if ($phone_cell) {
            $phone_values = $phone_cell[array_keys($phone_cell)[0]]['values'] ?? null;
            if ($phone_values) {
                $phone_values = array_map(function ($phone) use ($contact, $last_modified, $linked_leads) {
                    $phone = $this->trimPhone($phone['value']);
                    if ($contact[$last_modified] < 0) {
                        $last_mod = time();
                        // $this->log->log('Контакт с ID ' . $contact['id'] . ' с отрицательным last_modified, в БД будет записан с текущим временем');
                    } else {
                        $last_mod = $contact[$last_modified];
                    }
                    if (strlen($phone)) return ['id' => $contact['id'], 'phone' => $phone, 'last_mod' => $last_mod, 'responsible_user_id'=>$contact['responsible_user_id'], 'linked_leads'=>json_encode($linked_leads)];
                }, $phone_values);
                $phone_values = array_filter($phone_values, function ($phone) {
                    if ($phone && $phone['phone']) {
                        return $phone['phone'];
                    } else {
                        return null;
                    }
                });
                $phone_values_unique = [];
                array_walk($phone_values, function ($item) use (&$phone_values_unique) {
                    if (count($phone_values_unique)) {
                        $condition = in_array($item['id'], array_column($phone_values_unique, 'id')) && in_array($item['phone'], array_column($phone_values_unique, 'phone'));
                        if (!$condition) $phone_values_unique[] = $item;
                    } else {
                        $phone_values_unique[] = $item;
                    }
                });
                $this->db_phones = [...$this->db_phones, ...$phone_values_unique];
            }
        }
        $email_cell = array_filter($contact[$custom_fields], function ($cf) use ($code) {
            if (in_array($code, array_keys($cf))) {return $cf[$code] === 'EMAIL'; } else { return null; }});
        if ($email_cell) {
            $email_values = $email_cell[array_keys($email_cell)[0]]['values'] ?? null;
            if ($email_values) {
                $email_values = array_filter($email_values, function ($email) {
                    return filter_var($email['value'], FILTER_VALIDATE_EMAIL) && preg_match('/@.+./', $email['value']);
                });
                $email_values = array_map(function ($email) use ($contact, $last_modified, $linked_leads) {
                    if ($contact[$last_modified] < 0) {
                        $last_mod = time();
                        // $this->log->log('Контакт с ID ' . $contact['id'] . ' с отрицательным last_modified, в БД будет записан с текущим временем');
                    } else {
                        $last_mod = $contact[$last_modified];
                    };
                    return ['id' => $contact['id'], 'email' => $email['value'], 'last_mod' => $last_mod, 'responsible_user_id'=>$contact['responsible_user_id'], 'linked_leads'=>json_encode($linked_leads)];
                }, $email_values);
                $this->db_emails = [...$this->db_emails, ...$email_values];
            }
        }
        if ($linked_leads[0]) $this->dbLeads = [...$this->dbLeads, ...$linked_leads];
        array_walk($linked_leads, function($lead_id) use ($phone_values_unique, $email_values){
            if ($phone_values_unique){
                array_walk($phone_values_unique, function($phone_record) use ($lead_id){
                    $lead_id ? $lead_index = (string)$lead_id : $lead_index = '00000000';
                    $this->dbTestPhones[] = [...$phone_record, ...['lead_id'=>$lead_id,'rec_id'=>hash('md2',$phone_record['id'].$phone_record['phone'].$lead_index)]];
                });
            }
            if ($email_values){
                array_walk($email_values, function($email_record) use ($lead_id){
                    $lead_id ? $lead_index = $lead_id : $lead_index = '00000000';
                    $this->dbTestEmails[] = [...$email_record, ...['lead_id'=>$lead_id,'rec_id'=>hash('md2',$email_record['id'].$email_record['email'].$lead_index)]];
                });
            }
        });
    }

    /**
     * Get users from the amo account
     * @return array
     */
    public function getUsers(): array
    {
        $link = 'https://' . SUBDOMAIN . '.amocrm.ru' . '/api/v4/users';
        $headers = ['Authorization: Bearer ' . $this->client->access_token->getToken(), 'Content-Type: application/json'];
        $this->retry = 0;
        $response = json_decode($this->makeRequest('GET', $link, $headers, null, true, false), true);
        return $response['_embedded']['users'] ?? [];
    }

    /**
     * Подбирает основного пользователя, к которому будет прикреплен контакт
     * @param array $main_users
     * @param array|null $embedded_leads
     * @return int
     */
    private function getMainUser(array $main_users, array $embedded_leads = null): int|null
    {
        list($sorted, $leads, $main_user) = [[], [], null];
        array_walk($embedded_leads, function ($raw_leads, $contact_id) use (&$leads) {
            $new_leads = array_column($raw_leads['values'], 'value')[0];
            array_walk($new_leads, function ($lead_values, $lead_id) use (&$leads, $contact_id) {
                $leads[] = ['responsible_user_id' => null, 'lead_id' => (int)$lead_id, 'pipeline' => $lead_values['pipeline']['label'], 'status' => $lead_values['status']['label']];
            });
        });
        $leads = $this->getLeads(array_values(array_column($leads, 'lead_id')));
        $leads = array_filter($leads, function ($lead) {
            return in_array($lead['status_id'], array_merge(CALLING_PIPELINE_STATUSES, OTHER_SOURCES_PIPELINE_STATUSES));
        });
        if (count($leads)) {
            array_walk($leads, function ($lead) use (&$sorted) { $sorted[$lead['created_at']] = $lead; });
            krsort($sorted);
            $active_users_id = array_values(array_column($this->users,'id'));
            foreach ($sorted as $lead_time => $current_lead) {
                if (in_array($current_lead['responsible_user_id'], $active_users_id)) {
                    $main_user = $current_lead['responsible_user_id'];
                    break;
                }
            }
        }
        return $main_user;
    }

    /**
     * Основная функция по объединению контактов
     * @param array $duplicates
     * @return bool
     * @throws Exception
     */
    private function processDuplicates(array $duplicates): bool
    {
        sort($duplicates);
        // $this->log->log('id дублей на объединение в один контакт, получаем информацию для объединения из амо (getMergeInfo) : ' . json_encode($duplicates));
        $this->get_merge_info_attempts = 0;
        $differences = $this->getMergeInfo(array_values($duplicates));
        $compare_values = $differences['compare_values'] ?? null;
        $compare_fields = $differences['compare_fields'] ?? null;
        if (!$compare_values || !$compare_fields) {
            // $this->log->log('Ошибка в запросе при сравнении контактов, проблемные контакты: ' . json_encode($duplicates) . ', переход к следующей пачке');
            return true;
        }
        if (!isset($compare_fields['cfv_' . EMAIL_FIELD_ID]) && !isset($compare_fields['cfv_' . PHONE_FIELD_ID])) {
            // $this->log->log('Неправильные id полей, завершение работы скрипта');
            die();
        }
        //Получение id компании
        $other_companies = [];
        $company = null;
        if (isset($compare_values['COMPANY_UID'])) {
            if (isset($compare_values['COMPANY_UID'][$duplicates[0]])) {
                $company = (int)$compare_values['COMPANY_UID'][$duplicates[0]]['values'][0]['value'];
            } else {
                $company = (int)array_column($compare_values['COMPANY_UID'], 'values')[0][0]['value'];
            }
            $company_values = array_values($compare_values['COMPANY_UID']);
            array_walk($company_values, function ($item) use (&$other_companies) {
                $other_companies[] = (int)$item['values'][0]['value'];
            });
            $other_companies = array_diff($other_companies, [$company]);
        }
        $result = [];
        if ($company) {
            $result['double'] = ['companies' => ['result_element' => ['COMPANY_UID' => $company, 'ID' => $company]]];
        }
        //Установка ответственного (ищем ответственного в самой старой активной привязанной сделке или берем из основного контакта, если сделок нет или в сделках неактивные пользователи)
        isset($compare_values['LEADS']) && count($compare_values['LEADS']) ?
            $main_user = $this->getMainUser($compare_values['MAIN_USER_ID'], $compare_values['LEADS'])
            : $main_user = null;
        $main_user ? $result_element['MAIN_USER_ID'] = $main_user : $result_element['MAIN_USER_ID'] = $compare_values['MAIN_USER_ID'][$duplicates[0]]['values'][0]['value'];
        $note_array = []; // Массив для примечания в объединенный контакт
        // Добавляем в массив для примечания id контактов в виде ключей, используем 'MAIN USER ID', так как ответственный есть во всех контактах => id каждого контакта есть в массиве MAIN USER ID в compare_values
        array_walk($compare_values['MAIN_USER_ID'], function ($value, $key) use (&$note_array) {
            $note_array[$key] = [];
        });
        $this->unique_contact_phones = [];
        $this->unique_contact_emails = [];
        foreach ($compare_values as $entity => $contacts_with_values) {
            $entity_key_exploded = explode('_', $entity);
            $prefix = $entity_key_exploded[0] ?? null;
            $field_id = $entity_key_exploded[1] ?? null;
            foreach ($contacts_with_values as $contact_id => $entity_array) {
                if ($prefix == 'cfv') {
                    $field_id == PHONE_FIELD_ID ? $is_phone = true : $is_phone = false;
                    $field_id == EMAIL_FIELD_ID ? $is_email = true : $is_email = false;
                    if ($is_phone || $is_email) {
                        $result_element['cfv'][$field_id] ?? $result_element['cfv'][$field_id] = [];
                        $values_to_add = $this->prepareValues($entity_array['values'], $is_phone, $is_email);
                        $result_element['cfv'][$field_id] = array_merge($result_element['cfv'][$field_id], $values_to_add);
                        $prepared = $this->preparePhonesEmailsForNotes($entity_array['values']);
                        $note_array[$contact_id][$entity] = $compare_fields[$entity]['name'] . ': ' . implode(', ', $prepared) . PHP_EOL;
                    } else {
                        $prepared = $this->prepareValues($entity_array['values']);
                        if (isset($result_element['cfv'][$field_id])) {
                            if (count($prepared)) {
                                $note_array[$contact_id][$entity] = $compare_fields[$entity]['name'] . ': ' . implode(', ', $prepared) . PHP_EOL;
                            }
                            continue;
                        }
                        if (count($prepared)) {
                            $result_element['cfv'][$field_id] = $prepared[0];
                            $prepared = array_slice($prepared, 1);
                            if (count($prepared)) {
                                $note_array[$contact_id][$entity] = $compare_fields[$entity]['name'] . ': ' . implode(', ', $prepared) . PHP_EOL;
                            }
                        }
                    }
                } elseif ($entity == 'LEADS') {
                    $result_element[$entity] ?? $result_element[$entity] = [];
                    $entity_leads = $this->prepareLeads($entity_array['values']);
                    $result_element[$entity] = array_merge($result_element[$entity], $entity_leads);
                } elseif ($entity == 'TAGS') {
                    $result_element[$entity] ?? $result_element[$entity] = [];
                    $entity_tags = $this->prepareValues($entity_array['values']);
                    $result_element[$entity] = array_merge($result_element[$entity], $entity_tags);
                } elseif ($entity == 'COMPANY_UID') {
                    if ($result['double']['companies']['result_element']['COMPANY_UID'] !== (int)$entity_array['values'][0]['value']) {
                        $note_array[$contact_id][$entity] = $entity . ': ' . (int)$entity_array['values'][0]['value'] . ', ' . $entity_array['values'][0]['label'] . PHP_EOL;
                    }
                } elseif ($entity == 'MAIN_USER_ID') {
                    if ($result_element[$entity] !== (int)$entity_array['values'][0]['value']) {
                        $note_array[$contact_id][$entity] = $entity . ': ' . (int)$entity_array['values'][0]['value'] . ', ' . $entity_array['values'][0]['label'] . PHP_EOL;
                    }
                } else {
                    $prepared = $this->prepareValues($entity_array['values']);
                    if (isset($result_element[$entity])) {
                        if (count($prepared)) {
                            $note_array[$contact_id][$entity] = $entity . ': ' . implode(', ', $prepared) . PHP_EOL;
                        }
                        continue;
                    }
                    if (count($prepared)) {
                        $result_element[$entity] = $prepared[0];
                        $prepared = array_slice($prepared, 1);
                        if (count($prepared)) {
                            $note_array[$contact_id][$entity] = $entity . ': ' . implode(', ', $prepared) . PHP_EOL;
                        }
                    }
                }
            }
        }
        $result['id'] = array_values($duplicates);
        $result['result_element'] = $result_element;
        $result['result_element']['ID'] = $result['id'][0];
        $this->mergeContacts($result);
        $this->notes_array = $this->getNotesFromAmo([$result['result_element']['ID']]); # забираем старые примечания
        array_walk($this->notes_array, function ($note, $key) {
            if (isset($note['params']['text'])) {
                $this->notes_array[$key]['text'] = $note['params']['text'];
                unset($this->notes_array[$key]['params']);
            }
        });
        $this->notesForContacts($note_array, $this->notes_array, $result);
        if (count($other_companies)) $this->notesForCompanies($result, $other_companies, $compare_values);
        $this->notes_array = [];
        return true;
    }

    /**
     * Объединение контактов через фронтовый запрос
     * @param array $result
     * @return bool
     * @throws Exception
     */
    private function mergeContacts(array $result): bool
    {
        $link = 'https://' . BASE_DOMAIN . '/ajax/merge/contacts/save';
        $body = $result;
        $headers = ['Accept: application/json, text/javascript, */*; q=0.01', 'Authorization: Bearer ' . $this->token, 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With: XMLHttpRequest', 'Cookie: session_id=' . SESSION_ID];
        // $this->log->log('Объединение контактов в массив: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
        try {
            $this->retry = 0;
            $merge_result = json_decode($this->makeRequest('POST', $link, $headers, $body, false, true), true);
            // $this->log->log('Результат объединения: ' . json_encode($merge_result, JSON_UNESCAPED_UNICODE));
            $response = $merge_result['response'] ?? [];
            if (count($response)) {
                if (count($response['multiactions']['set']['errors'])) {
                    if (++$this->merge_attempts < MERGE_ATTEMPTS) {
                        // $this->log->log('Ошибка при объединении, пробуем повторный запрос: ' . json_encode($response['multiactions']['set']['errors']));
                        sleep(SECONDS_FOR_SLEEP);
                        $_ = $this->mergeContacts($result);
                    } else {
                        // $this->log->log('Сбой при объединении после ' . $this->merge_attempts . ' попыток, завершение работы');
                        $this->db->truncate_processed($this);
                        die();
                    }
                }
            } else {
                if (++$this->merge_attempts < MERGE_ATTEMPTS) {
                    // $this->log->log('Ошибка при объединении, пробуем повторный запрос');
                    sleep(SECONDS_FOR_SLEEP);
                    $_ = $this->mergeContacts($result);
                } else {
                    // $this->log->log('Сбой при объединении после ' . $this->merge_attempts . ' попыток, завершение работы');
                    $this->db->truncate_processed($this);
                    die();
                }
            }
        } catch (Exception $e) {
            if (++$this->merge_attempts < MERGE_ATTEMPTS) {
                // $this->log->log('Ошибка при объединении, пробуем повторный запрос : ' . $e->getMessage());
                sleep(SECONDS_FOR_SLEEP);
                $_ = $this->mergeContacts($result);
            } else {
                // $this->log->log('Сбой при объединении после ' . $this->merge_attempts . ' попыток, завершение работы');
                $this->db->truncate_processed($this);
                die();
            }
        }
        $this->merge_attempts = 0;
        return true;
    }

    /**
     * Подготовка значений custom fields для объединения, а также отсечение дублей значений телефонов и почт
     * @param array $values
     * @param bool $is_phone
     * @param bool $is_email
     * @return array
     */
    private function prepareValues(array $values, bool $is_phone = false, bool $is_email = false): array
    {
        $result = [];
        foreach ($values as $value) {
            if ($is_phone) {
                $decoded_value = json_decode(htmlspecialchars_decode($value['value']), true);
                $phone = $this->trimPhone($decoded_value['VALUE'], true);
                if (in_array($phone, $this->unique_contact_phones)) continue;
                if ($phone) {
                    $this->unique_contact_phones[] = $phone;
                } else {
                    continue;
                };
                $decoded_value['VALUE'] = $phone;
                $value['value'] = json_encode($decoded_value, JSON_UNESCAPED_UNICODE);
            } elseif ($is_email) {
                $decoded_value = json_decode(htmlspecialchars_decode($value['value']), true);
                if (in_array($decoded_value['VALUE'], $this->unique_contact_emails)) continue;
                $this->unique_contact_emails[] = $decoded_value['VALUE'];
                $value['value'] = json_encode($decoded_value, JSON_UNESCAPED_UNICODE);
            }
            $result[] = $value['value'];
        }
        return $result;
    }

    private function preparePhonesEmailsForNotes(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $decoded_value = json_decode(htmlspecialchars_decode($value['value']), true);
            $value['value'] = json_encode($decoded_value['VALUE'], JSON_UNESCAPED_UNICODE);
            $result[] = $value['value'];
        }
        return $result;
    }

    /**
     * Подготовка значений для сделок
     * @param array $values
     * @return array
     */
    private function prepareLeads(array $values): array
    {
        $result = [];
        foreach ($values[0]['value'] as $id => $entity) {
            $result[] = (string)$id;
        }
        return $result;
    }

    /**
     * Возвращает результат сравнения контактов (сравнение методами амо, запрос с фронтенда)
     * @param array $contacts
     * @return array
     */
    private function getMergeInfo(array $contacts): array
    {
        $link = 'https://' . SUBDOMAIN . '.amocrm.ru/ajax/merge/contacts/info/';
        $headers = ['Authorization: Bearer ' . $this->token, 'X-Requested-With: XMLHttpRequest', 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8'];
        $body = ['id' => $contacts];
        $this->retry = 0;
        $answer = $this->makeRequest('POST', $link, $headers, $body, true);
        $result = json_decode($answer, true);
        $response = $result['response'] ?? [];
        $status = $result['status'] ?? '';
        // $status === 'success' ? $this->log->log('Результат сравнения получен') : $this->log->log('Результат сравнения не получен');
        if (!count($response)) {
            if ($this->get_merge_info_attempts >= GET_MERGE_INFO_ATTEMPTS) {
                // $this->log->log('Не удалось получить данные для сравнения после ' . $this->get_merge_info_attempts . ' попыток');
                return $response;
            }
            $this->badlog->log('Пустые данные, повторная попытка отправки запроса номер ' . ++$this->get_merge_info_attempts);
            sleep(SECONDS_FOR_SLEEP);
            $response = $this->getMergeInfo($contacts);
        }
        return $response;
    }

    /**
     * Приведение телефонных номеров России и Беларуси к единому формату +7... и +375...
     * Для занесения в БД для поиска дублей используются только числовые значения ($compare=false), для сравнения при объединении - любые значения ($compare=true)
     * @param string $phone
     * @param bool $compare
     * @return string
     */
    public function trimPhone(string $phone, bool $compare = false): string
    {
        $unprocessed = $phone;
        $phone = preg_replace('/[^\d]/', '', $phone);
        $phone = preg_replace('/\A(7|8|)([3489]\d{9})\z/', '7$2', $phone);
        //$phone = preg_replace('/\A(375)(\d{9})\z/', '+375$2', $phone);
        //if (
        //    $compare && !preg_match('/\A\7[3489]\d{9}\z/', $phone)
        //&& !preg_match('/\A\+375\d{9}\z/', $phone)
        //) { $phone = $unprocessed; }
        return $phone;
    }

    /**
     * Добавление примечания в объединенный контакт и удаление старых примечаний
     * @param array $note_array
     * @param array $notes_from_duplicates
     * @param array $result
     * @return array
     * @throws Exception
     */
    private function notesForContacts(array $note_array, array $notes_from_duplicates, array $result): array
    {
        $text_for_note = '' . PHP_EOL . 'Дата: ' . date('Y/m/d H:i:s', time() + 3 * 60 * 60) . PHP_EOL . 'Значения полей :' . PHP_EOL;
        array_walk($note_array, function ($fields, $contact_id) use (&$text_for_note) {
            $text_for_note .= 'Contact ID: ' . $contact_id . PHP_EOL;
            $text_for_note .= implode('', array_values($fields));
        });
        $notes_body = $this->workingWithNotes($result['id'], $notes_from_duplicates, $text_for_note);
        if (count($notes_body)) {
            $answer = $this->makeNotes($notes_body);
        } else {
            return [];
        };
        if (count(array_values(array_column($answer, 'id')))) {
            if (count($this->old_notes)) {
                // $this->log->log('Удаление примечаний');  // найдены старые примечания
                foreach ($this->old_notes as $note) {
                    // $this->log->log("Массив на удаление примечания: " . json_encode($note, JSON_UNESCAPED_UNICODE));
                    $this->log->log('Результат удаления примечания: ' . $this->deleteNote($note));
                }
            } else {
                // $this->log->log('Старых примечаний об объединении нет');
            }
        }
        $this->old_notes = [];
        return $answer;
    }

    /**
     * @param array $new_notes
     * @return array
     */
    public function makeNotes(array $new_notes): array
    {
        $result_array = [];
        $notes_array = [];
        try {
            $note_service = $this->client->api_client->notes($new_notes[0]['element_type']);
            $notes = new NotesCollection();
            $id = 0;
            array_walk($new_notes, function ($note) use (&$notes_array, &$id) {
                $new_note = [
                    'id' => $id++,
                    'entity_id' => (int)$note['element_id'],
                    'created_by' => MAIN_USER_ID,
                    'note_type' => $note['note_type'],
                    'params' => $note['params'] ?? null,
                ];
                if (isset($note['text'])) { $new_note['params'] = ['text' => $note['text']]; }
                $notes_array[] = $new_note;
            });
            $notes_collection = $notes::fromArray($notes_array);
            $result = $note_service->add($notes_collection);
            $result_array = $result->toArray() ?? [];
        } catch (AmoCRMMissedTokenException $e) {
            $this->badlog->log('Error while creating notes service: ' . $e);
            die((string)$e);
        } catch (InvalidArgumentException|AmoCRMoAuthApiException|AmoCRMApiException $e) {
            $this->badlog->log('Error while creating notes: ' . $e);
        }
        return $result_array;
    }

    /**
     * Добавление примечаний в открепленные компании
     * @param array $result
     * @param array $other_companies
     * @param array $compare_values
     * @return array
     */
    private function notesForCompanies(array $result, array $other_companies, array $compare_values): array
    {
        $notes_for_companies = [];
        $note_for_company = 'Автоматическое открепление контактов: ' . date('Y/m/d h:i:s', time()) . ', cсылка на объединенный контакт: ' . PHP_EOL .
            CONTACT_BASE_URL . $result['id'][0] . PHP_EOL . 'Причина закрытия: объединение дублей контактов.' . PHP_EOL . 'Открепленные контакты: ' . PHP_EOL;
        array_walk($other_companies, function ($company_id) use (&$notes_for_companies, $note_for_company, $compare_values) {
            array_walk($compare_values['COMPANY_UID'], function ($company_array, $contact_id) use (&$note_for_company, $company_id, $compare_values) {
                if ($company_array['values'][0]['value'] === $company_id) {
                    $note_for_company .= 'ID ' . $contact_id . ' ' . $compare_values['NAME'][$contact_id]['values'][0]['value'] . PHP_EOL;
                }
            });
            $notes_for_companies[] = ['element_id' => $company_id, 'element_type' => 'companies', 'text' => $note_for_company, 'note_type' => 'common'];
        });
        $response = $this->makeNotes($notes_for_companies);
        // $this->log->log("Результат добавления примечания в открепленные компании: " . json_encode($response));
        return $response;
    }

    /**
     * Получение из амо примечаний об объединяемых контактах
     * @param array $contacts_id
     * @param string $entity_type
     * @param array $notes_id
     * @param array $note_types
     * @return array
     */
    public function getNotesFromAmo(array $contacts_id, string $entity_type = 'contacts', array $notes_id = [], array $note_types = ['common']): array
    {
        $notes_from_amo = [];
        try {
            $note_service = $this->client->api_client->notes($entity_type);
            $filter = new NotesFilter();
            $filter->setNoteTypes($note_types)->setEntityIds($contacts_id);
            $notes = $note_service->get($filter);
            $notes_from_amo = $notes->toArray() ?? [];
            if (count($notes_from_amo)) {
                array_walk($notes_from_amo, function ($note, $key) use (&$notes_from_amo) {
                    $text = $note['params']['text'] ?? null;
                    if ($text) $notes_from_amo[$key]['params']['text'] = htmlspecialchars_decode($text);
//                    $converted = preg_replace('/&quot;/','"',$note_phrase);
//                    $converted = preg_replace('/&amp;/','',$converted);
//                    $converted = preg_replace('/amp;/','',$converted);
//                    $converted = preg_replace('/quot;/','"',$converted);
                });
            }
        } catch (AmoCRMMissedTokenException|InvalidArgumentException|AmoCRMApiException $e) {
            $this->badlog->log('Error while creating a note service or getting notes, ' . $e->getMessage());
        }
        return $notes_from_amo;
    }

    /**
     * Получение старых примечаний, объединение и добавление нового текста, возврат текста нового примечания
     * @param array $contacts_id
     * @param array $amo_notes
     * @param string $new_text
     * @return array
     */
    private function workingWithNotes(array $contacts_id, array $amo_notes, string $new_text = ''): array
    {
        $main_id = $contacts_id[0]; #Основной контакт
        list($notes_body, $note_text) = [[],''];
        if (strlen($new_text) > MAX_NOTE_LENGTH) {
            // $this->log->log('Получилось слишком длинное примечание, в амо будет записан обрезанный вариант, полный вариант на диске');
            $filename = 'note_for_' . $main_id . '_' . date('y_m_d_H_i_s', time()) . '.txt';
            file_put_contents(__DIR__ . '/../../data/' . $filename, $new_text);
            $note_text = CHECK_STRING . PHP_EOL . substr($new_text, 0, MAX_NOTE_LENGTH - strlen(CHECK_STRING . PHP_EOL));
            $notes_body[] = ['element_id' => $main_id, 'element_type' => 'contacts', 'text' => $note_text, 'note_type' => 'common'];
            return $notes_body;
        }
        if (count($amo_notes)) $amo_notes = array_filter($amo_notes, function ($note) { # Отбираем старые примечания об объединении, если они не длинные
            return str_contains($note['text'], CHECK_STRING) && strlen($note['text']) < NOTE_LENGTH_LIMIT;
        });
        if (!count($amo_notes)) {
            // $this->log->log('В объединяемых контактах нет старых примечаний об объединении, создаём новое');
            $note_text = CHECK_STRING . PHP_EOL . $new_text;
            $notes_body[] = ['element_id' => $main_id, 'element_type' => 'contacts', 'text' => $note_text, 'note_type' => 'common'];
            return $notes_body;
        }
        // $this->log->log('Объединение старых примечаний');
        usort($amo_notes, function ($note1, $note2) {
            if (strlen($note1['text']) === strlen($note2['text'])) return 0;
            return strlen($note1['text']) > strlen($note2['text']) ? 1 : -1;
        }); // Сортируем по возрастанию длины старых примечаний
        while (count($amo_notes)) {
            $old_text = implode(PHP_EOL, array_values(array_column($amo_notes, 'text')));
            $old_text = str_replace(CHECK_STRING . PHP_EOL, '', $old_text);
            $note_text = CHECK_STRING . PHP_EOL . $new_text . PHP_EOL . $old_text;
            if (strlen($note_text) <= MAX_NOTE_LENGTH) {
                break;
            } else {
                $longest = array_pop($amo_notes); # Если превышена длина, то выбрасываем самое длинное примечание
            }
        }
        if (!count($amo_notes)) {
            // $this->log->log('Создаём отдельное примечание, так как при объединении со старыми примечаниями превышена допустимая длина примечания');
            $note_text = CHECK_STRING . PHP_EOL . $new_text;
        } else {
            $this->old_notes = array_map(function ($note) {
                return ['id' => $note['id'], 'element_id' => $note['entity_id'], 'element_type' => 1];
            }, $amo_notes); #Формируем массив старых примечаний на удаление
        }
        $notes_body[] = ['element_id' => $main_id, 'element_type' => 'contacts', 'text' => $note_text, 'note_type' => 'common'];
        return $notes_body;
    }

    /**
     * Удаление примечания через фронтовый запрос
     * @param array $note
     * @return bool
     * @throws Exception
     */
    private function deleteNote(array $note): bool
    {
        $headers = ['Authorization: Bearer ' . $this->token, 'Content-Type:application/x-www-form-urlencoded', 'X-Requested-With: XMLHttpRequest', 'Cookie: session_id=' . SESSION_ID];
        $params = ['parent_element_id' => $note['element_id'], 'parent_element_type' => $note['element_type']];
        $link = 'https://' . BASE_DOMAIN . '/private/notes/edit2.php?' . http_build_query($params);
        $body = ['ID' => $note['id'], 'ACTION' => 'NOTE_DELETE'];
        $this->retry = 0;
        $response = $this->makeRequest('POST', $link, $headers, $body, false, true) ?? null;
        // $response ? $this->log->log('Примечание удалено') : $this->log->log('Примечание не удалено: ' . json_encode($note));
        return !!$response;
    }

    /**
     * Ищет контакты, которые были удалены с момента последней загрузки в БД, и отправляет на удаление из БД
     * @param bool $new
     * @param bool $continue
     * @return void
     * @throws Exception
     */
    private function trash($trash_updated, $updated_from, bool $new = false, bool $continue = false): void
    {
        //$today = date('d.m.Y', time() - TIME_OFFSET);
        //$yesterday = date('d.m.Y', time() - TIME_OFFSET - 24 * 60 * 60);
        $deadline = max($trash_updated, $updated_from);
        $new_deadline = $this->startTime;
        // $this->log->log('Deleted contacts checking...>');
        $this->db_trash = $this->getEvents(['limit'=>100,'filter'=>['entity'=>['contact'],'type'=>['contact_deleted'],'created_at'=>['from'=>$deadline]]]);
//        if (!$this->getTrash($deadline)) {
//            $this->log->log('Удаленные контакты не получены, завершение работы');
//            die();
//        }
        if ($trash = count($this->db_trash)) {
            $this->cache->set($this->prefix . '_trash', $new_deadline, 31536000);
            // $this->log->log('--->Deleted contacts were found: ' . $trash);
//            $this->db_trash = array_map(function ($contact) use ($today, $yesterday) {
//                $contact['date_modified'] = preg_replace('/Вчера/', $yesterday, $contact['date_modified']);
//                $contact['date_modified'] = preg_replace('/Сегодня/', $today, $contact['date_modified']);
//                $contact['date_modified'] = strtotime($contact['date_modified'])+24*60*60-1;
//                return $contact;
//            }, $this->db_trash);
//            $this->db_trash = array_filter($this->db_trash, function ($contact) use ($deadline) {
//                return $contact['date_modified'] > $deadline;
//            });
//            $this->log->log('Удаленных контактов после фильтра: ' . $trash = count($this->db_trash));
//            if ($trash) $this->db->purge_trash(array_column($this->db_trash, 'id'), $new, $continue);
            $this->db->purgeTrash(array_column($this->db_trash, 'entity_id'), $new, $continue);
            $this->db_trash = [];
        } else {
            // $this->log->log('--->There were no deleted contacts.');
        }
        // $this->log->log('Deleted leads checking...>');
        $events = $this->getEvents(['limit'=>100,'filter'=>['entity'=>['lead'],'type'=>['lead_deleted'],'created_at'=>['from'=>$deadline]]]);
        if ($del_amount = count($events)) {
            // $this->log->log('--->Deleted leads were found: '.$del_amount);
            $this->db->deleteFromTable(LEADS_TABLE, 'id', array_column($events, 'entity_id'));
        } else {
            // $this->log->log('--->There were no deleted leads.');
        };
    }

    /**
     * @param array $params
     * @param string $event_link
     * @param int $current_page
     * @return array
     */
    public function getEvents(array $params, string $event_link = 'https://'.BASE_DOMAIN.'/api/v4/events?', int $current_page = 0) :array
    {
        list($total, $headers)  = [[],['Authorization: Bearer ' . $this->getToken(), 'Content-Type: application/json']];
        do {
            $params['page'] = ++$current_page;
            $this->retry = 0;
            $events = json_decode($this->makeRequest('GET',$event_link.(http_build_query($params)), $headers),true)['_embedded']['events'] ?? [];
            $total = [...$total, ...$events];
        } while (count($events));
        return $total;
    }

//    /**
//     * Запрос в корзину через CURL, получение удаленных контактов из корзины
//     * @param int $deadline
//     * @return bool
//     */
//    public function getTrash(int $deadline): bool
//    {
//        $this->offset = 0;
//        $this->log->log('Очистка БД от удалённых контактов, которые были удалены после ' . date('y/m/d H:i:s', $deadline));
//        $contacts = [true];
//        while (count($contacts)) {
//            $params = ['filter_date_switch' => 'modified', 'filter_date_from' => $deadline, 'useFilter' => 'y', 'page' => ++$this->offset, 'ELEMENT_COUNT' => 200, 'element_type' => 1];
//            $this->log->log('Корзина: получение страницы # ' . $this->offset);
//            $link = 'https://' . SUBDOMAIN . '.amocrm.ru' . TRASH_URL . '?' . http_build_query($params);
//            $headers = ['Authorization: Bearer ' . $this->token, 'Content-Type: application/x-www-form-urlencoded', 'X-Requested-With: XMLHttpRequest'];
//            $this->retry = 0;
//            $response = $this->makeRequest('GET', $link, $headers, null, true);
//            $contacts = json_decode($response, true)['response']['items'] ?? [];
//            //if (!count($contacts)) $this->log->log(' - пустая страница, стоп');
//            $this->db_trash = array_merge($this->db_trash, $contacts);
//        }
//        $this->offset = 0;
//        if ($this->response_code === 200 || $this->response_code === 202 || $this->response_code === 204) {
//            return true;
//        } else {
//            return false;
//        }
//    }

    /**
     * Получение токена
     * @return string
     */
    private function getToken(): string
    {
        $token = $this->client->access_token->getToken();
        if (!$token) $this->log->log('Не удалось получить токен');
        return $token;
    }

    /**
     * Получение сделок по API V4
     * @param array $leads_id
     * @param array $params
     * @return array
     */
    public function getLeads(array $leads_id=[], array $params = []): array
    {
        // $this->log->log('Loading leads ... > ' . json_encode(['id_filter: '=>$leads_id,'params: '=>$params]));
        if (count($leads_id)) $params['filter']['id'] = $leads_id;
        $request_link = 'https://' . SUBDOMAIN . '.amocrm.ru/api/v4/leads?' . http_build_query($params);
        $headers = ['Authorization: Bearer ' . $this->getToken(), 'Content-Type: application/json'];
        $this->retry = 0;
        $response = $this->makeRequest('GET', $request_link, $headers, null, true);
        $leads = json_decode($response, true)['_embedded']['leads'] ?? [];
        // if ($count = count($leads)) {$this->log->log('Leads were received: '.$count); } else { $this->log->log('Got empty deals array'); }
        return $leads;
    }

    /**
     * @return void
     */
    public function fixingLeads(): void
    {
        list($deadline, $leads_collection, $current_page) = [1666396800,[],0];
        $params = [
            'limit'=>AMO_MAX_LIMIT,
            'order'=>['id'=>'asc'],
            'with'=>'contacts',
            'filter'=>[
                'created_at'=>['to'=>$deadline]
            ]
        ];
        foreach([CALLING_PIPELINE, OTHER_SOURCES_PIPELINE] as $pipe_id){
            $pipe_id === CALLING_PIPELINE ? $statuses = CALLING_PIPELINE_STATUSES : $statuses = OTHER_SOURCES_PIPELINE_STATUSES;
            foreach($statuses as $status){
                $params['filter']['statuses'][] = ['pipeline_id'=>$pipe_id, 'status_id'=>$status];
            }
        }
        do {
            $params['page'] = ++$current_page;
            $leads = $this->getLeads([],$params);
            $leads_collection = [...$leads_collection, ...$leads];
        } while (count($leads));

        $mapped_leads = [];
        array_walk($leads_collection, function($lead) use (&$mapped_leads) {
            $mapped_leads[$lead['id']] = $lead;
        });
        $leads_to_contacts = [];
        foreach ($leads_collection as $lead){
            if (isset($lead['_embedded']['contacts']) && count($lead['_embedded']['contacts'])){
                foreach($lead['_embedded']['contacts'] as $contact){
                    if (isset($contact['is_main']) && $contact['is_main']){
                        $leads_to_contacts[$lead['id']] = $contact['id'];
                        break;
                    }
                }
            }
        }
        $contacts_to_leads = array_flip($leads_to_contacts);
        $contacts_id = array_values($leads_to_contacts);
        $contacts_collection = [];
        $chunked_contacts = array_chunk($contacts_id, AMO_MAX_LIMIT);
        foreach($chunked_contacts as $contact_chunk) {
            $params = ['limit'=>AMO_MAX_LIMIT, 'page'=>1, 'filter'=>['id'=>$contact_chunk]];
            $contacts = $this->getContactsV4($params);
            $contacts_collection = [...$contacts_collection, ...$contacts];
        }

        $mapped_contacts = [];
        array_walk($contacts_collection, function($contact) use (&$mapped_contacts) {
            $mapped_contacts[$contact['id']] = $contact;
        });
        $leads_to_update = [];
        foreach($leads_collection as $lead){
            $model = [];
            $contact_id = $leads_to_contacts[$lead['id']];
            $contact = $mapped_contacts[$contact_id] ?? null;
            if ($contact) $model['name'] = $contact['name'];
            $model['id'] = $lead['id'];
            if (isset($lead['custom_fields_values'])){
                foreach($lead['custom_fields_values'] as $cfv){
                    if ($cfv['field_id'] === 685627){
                        $old_month = $cfv['values'][0]['value'];
                        $current_year = (int)preg_replace('/[^\d]/','',$old_month) ?? null;
                        $matches = [];
                        preg_match('/^\S*/', trim($old_month), $matches);
                        $current_month = $matches[0] ?? null;
                        $years = YEARS;
                        $months = MONTHS;
                        if ($current_year){
                            $year_value_id = array_values(array_filter($years, function($year) use ($current_year) {
                                return $year['value'] === (int)$current_year;
                            }))[0]['id'];
                            $model['custom_fields_values'][] = ['field_id'=>YEARS_FIELD, 'values'=>[['enum_id'=>(int)$year_value_id]]];
                        }
                        if ($current_month){
                            $month_value_id = array_values(array_filter($months, function($month) use ($current_month){
                                return $month['value'] === $current_month;
                            }))[0]['id'];
                            $model['custom_fields_values'][] = ['field_id'=>MONTHS_FIELD, 'values'=>[['enum_id'=>(int)$month_value_id]]];
                        }
                    }
                }
            }
            $leads_to_update[] = $model;
        }
        $contacts_to_update = [];
        foreach($contacts_collection as $contact){
            $model = [];
            $lead_id = $contacts_to_leads[$contact['id']] ?? null;
            if ($lead_id) $lead = $mapped_leads[$lead_id] ?? null;
            if ($lead){
                if ($lead['responsible_user_id']!==$contact['responsible_user_id']){
                    $model['id'] = $contact['id'];
                    $model['responsible_user_id'] = $lead['responsible_user_id'];
                    $contacts_to_update[] = $model;
                }
            }
        }

        $chunked = array_chunk($leads_to_update, AMO_MAX_LIMIT);
        foreach($chunked as $body){
            $this->patch_leads($body);
        }
        $chunked = array_chunk($contacts_to_update, AMO_MAX_LIMIT);
        foreach($chunked as $body){
            $this->patch_contacts($body);
        }
        exit();
    }

    public function patch_leads($body): array
    {
        list($method, $amo_link) = ['PATCH', 'https://'.BASE_DOMAIN .'/api/v4/leads'];
        $headers = ['Authorization: Bearer ' . $this->client->access_token->getToken(), 'Content-Type: application/json'];
        $response = json_decode($this->makeRequest($method, $amo_link, $headers, $body, true, false),true);
        return $response['_embedded']['leads'] ?? [];
    }

    public function patch_contacts($body): array
    {
        list($method, $amo_link) = ['PATCH', 'https://'.BASE_DOMAIN . '/api/v4/contacts'];
        $headers = ['Authorization: Bearer ' . $this->client->access_token->getToken(), 'Content-Type: application/json'];
        $response = json_decode($this->makeRequest($method, $amo_link, $headers, $body, true, false),true);
        return $response['_embedded']['contacts'] ?? [];
    }

    /**
     * @return void
     */
    public function selectContactsFront(): void
    {
        list($amo_contacts, $contacts_by_id, $contacts, $filter_page, $request_body) = [[],[],[],0,
            [
                'useFilter' => 'y',
                'filter'=>['pipe'=>[CALLING_PIPELINE => CALLING_PIPELINE_STATUSES, OTHER_SOURCES_PIPELINE => OTHER_SOURCES_PIPELINE_STATUSES]],
                'ELEMENT_COUNT'=>200,
                'element_type' => 1
            ]
        ];
        do {
            $request_body['page'] = ++$filter_page;
            $batch = $this->getContactsFront($request_body)['items'] ?? [];
            $filtered_batch = array_filter($batch, function($contact){
                if($contact['leads']){
                    return count($contact['leads']) > 1;
                } else {
                    return null;
                }
            });
            $active_batch = [];
            array_walk($filtered_batch, function($contact, $contact_key) use (&$active_batch) {
                if($contact['leads']){
                    foreach($contact['leads'] as $lead_key=>$lead_array){
                        if (in_array($lead_array['id'], [142,143])){
                            unset($contact['leads'][$lead_key]);
                        }
                    }
                }
                if (count($contact['leads'])) { $active_batch[$contact_key] = $contact; };
            });
            $active_batch = array_filter($active_batch, function($contact){
                if($contact['leads']){
                    return count($contact['leads']) > 1;
                } else {
                    return null;
                }
            });
            $contacts = array_merge($contacts, $active_batch);
        } while (count($batch));

        count($contacts) ? $this->log->log('There are '.count($contacts).' contacts with duplicated leads') : $this->log->log('There are no duplicates by leads');
        $chunked_contacts = array_chunk(array_values(array_column($contacts, 'id')), AMO_MAX_LIMIT);
        foreach ($chunked_contacts as $chunk) {
            $batch = $this->getContactsV4(['page'=>1, 'limit'=>AMO_MAX_LIMIT, 'filter'=>['id'=>$chunk]]);
            $amo_contacts = [...$amo_contacts, ...$batch];
        }
        unset($chunk, $chunked_contacts, $batch, $filtered_batch);
        array_walk($amo_contacts, function($current_contact) use (&$contacts_by_id) {
            $contacts_by_id[$current_contact['id']] = $current_contact;
        });

        foreach($contacts as $contact)
        {
            $this->joinLeadsInfo($contact, $contacts_by_id[$contact['id']]);
        }
    }

    /**
     * Запрос через API V4 на получение контактов
     * @param $params
     * @return array
     */
    public function getContactsV4($params): array
    {
        $amo_link = 'https://' . SUBDOMAIN . '.amocrm.ru'.'/api/v4/contacts?'.http_build_query($params);
        $headers = ['Authorization: Bearer ' . $this->client->access_token->getToken(), 'Content-Type: application/json'];
        $this->retry = 0;
        $response = json_decode($this->makeRequest('GET', $amo_link, $headers, null, true, false),true);
        return $response['_embedded']['contacts'] ?? [];
    }

    /**
     * @param array $contact
     * @param array $full_contact
     * @return void
     */
    public function joinLeadsInfo(array $contact, array $full_contact): void
    {
        $duplicates = array_values(array_column($contact['leads'], 'deal_id'));
        $this->log->log('Merging multiple leads ' . json_encode($duplicates) .' from the contact ' . $contact['id']);
        $duplicates = array_diff($duplicates, $this->mergedLeads);
        if (count($duplicates)<2){
            $this->log->log('The contact '.$contact['id'].' has no duplicated leads, they have been merged already');
            return;
        }
        sort($duplicates);
        $main_lead = $duplicates[0];
        $compare_data = $this->comparingLeads($duplicates);
        list($compare_fields, $compare_values) = [$compare_data['compare_fields'], $compare_data['compare_values']];
        $result_element['ID'] = $main_lead;
        $result_element['NAME'] = $contact['name']['text'];
        $result_element['MAIN_USER_ID'] = (int)$compare_values['MAIN_USER_ID'][$main_lead]['values'][0]['value'];;
        $result_element['DATE_CREATE'] = $compare_values['DATE_CREATE'][$main_lead]['values'][0]['value'];
        $result_element['STATUS'] = (int)$compare_values['STATUS'][$main_lead]['values'][0]['value'];
        $result_element['PIPELINE_ID'] = (int)$compare_values['PIPELINE_ID'][$main_lead]['values'][0]['value'];
        $result_element['CONTACTS'] = [];
        foreach($compare_values['CONTACTS'] as $current_lead_id=>$current_values){
            $result_element['CONTACTS'] = [...$result_element['CONTACTS'], ...array_keys($current_values['values'][0]['value'])];
        }
        $result_element['CONTACTS'] = array_map('intval', array_unique($result_element['CONTACTS']));
        unset($compare_values['CONTACTS']);
        if (isset($compare_values['PRICE'])){
            if (isset($compare_values['PRICE'][$main_lead])) {
                $result_element['PRICE'] = $compare_values['PRICE'][$main_lead]['values'][0]['value'];;
            } else {
                foreach($compare_values['PRICE'] as $key_lead_id => $price){
                    $result_element['PRICE'] = $price['values'][0]['value'];
                    break;
                }
            }
        } else {
            $result_element['PRICE'] = 0;
        }
        if (isset($compare_values['COMPANY_UID'])){
            if (isset($contact['company_name']['id'])) {
                $result_element['COMPANY_UID'] = (int)$contact['company_name']['id'];
            } else {
                foreach($compare_values['COMPANY_UID'] as $key_lead_id => $company){
                    $result_element['COMPANY_UID'] = (int)$company['values'][0]['value']; //Otherwise we take the first company in the list
                    break;
                }
            }
        }
        if (isset($compare_values['TAGS'])){
            $result_element['TAGS'] = [];
            array_walk($compare_values['TAGS'], function($tags_array, $key_lead_array) use (&$result_element) {
                $lead_tags = array_values(array_column($tags_array['values'],'value'));
                $result_element['TAGS'] = [...$result_element['TAGS'],...$lead_tags];
            });
            $result_element['TAGS'] = array_unique($result_element['TAGS']);
            unset($compare_values['TAGS']);
        }
        foreach($compare_values as $entity_key => $leads_w_val){
            if (!str_contains($entity_key, 'cfv')) continue;
            $field_id = (int)preg_replace('/\D/', '',$entity_key);
            if (isset($leads_w_val[$main_lead])){
                $result_element['cfv'][$field_id] = $leads_w_val[$main_lead]['values'][0]['value'];
            } else {
                foreach($leads_w_val as $lead_id => $current_lead){
                    $result_element['cfv'][$field_id] = $current_lead['values'][0]['value'];
                    break;
                }
            }
        }
        $note_array[] = '#Объединение сделок#'. PHP_EOL . date('Y/m/d H:i:s', time()+10800).' MSK' . PHP_EOL;
        $note_array[] = 'ID сделок: '.json_encode($duplicates).PHP_EOL;
        $note_array[] = 'Значения полей и статусов:'.PHP_EOL;
        array_walk($compare_values, function($entity, $key) use (&$note_array){
            $note_array[] = $key. ':' .PHP_EOL;
            array_walk($entity, function($lead, $lead_id) use (&$note_array){
                $note_array[] = $lead_id.': '.$lead['values'][0]['value'].', ';
                if ($lead['values'][0]['value'] !== $lead['values'][0]['label']) {
                    $note_array[] = $lead['values'][0]['label'].', ';
                }
            });
            $note_array[] = PHP_EOL;
        });
        $note_text = implode('',$note_array);
        $notes_body = [];
        array_walk($result_element['CONTACTS'], function($contact_id) use (&$notes_body, $note_text) {
            $notes_body[] = ['element_id' => $contact_id, 'element_type' => 'contacts', 'text' => $note_text, 'note_type' => 'common'];
        });
        if ($result_element['MAIN_USER_ID'] !== (int)$full_contact['responsible_user_id'] ){
            $this->updateContactV4($contact['id'], $result_element['MAIN_USER_ID']);
        }
        $merge_result = $this->mergeLeads(['id'=>$duplicates, 'result_element'=>$result_element]);
        if ($merge_result) {
            $this->mergedLeads = array_unique([...$this->mergedLeads, ...array_slice($duplicates, 1)]);
            $this->makeNotes($notes_body);
        };
    }

    /**
     * @param $contact_id
     * @param $responsible_user_id
     * @return array
     */
    public function updateContactV4($contact_id, $responsible_user_id): array
    {
        $request_link = 'https://' . BASE_DOMAIN . '/api/v4/contacts';
        $request_body = [
            [
                'id'=>(int)$contact_id,
                'responsible_user_id'=>(int)$responsible_user_id]
        ];
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $this->getToken()];
        $this->retry = 0;
        $update_result = json_decode($this->makeRequest('PATCH', $request_link, $headers, $request_body, true, false), true);
        return $update_result['_embedded']['contacts'] ?? [];
    }

    /**
     * @param array $body
     * @return bool
     */
    private function mergeLeads(array $body): bool
    {
        $request_link = 'https://' . BASE_DOMAIN . '/ajax/merge/leads/save';
        $request_body = $body;
        $headers = [
            'Accept: application/json, text/javascript, */*; q=0.01', 'Authorization: Bearer ' . $this->getToken(),
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With: XMLHttpRequest',
            //'Cookie: session_id=' . SESSION_ID
        ];
        $this->log->log('Объединение сделок: ' . json_encode($request_body, JSON_UNESCAPED_UNICODE));
        $this->retry = 0;
        $merge_result = json_decode($this->makeRequest('POST', $request_link, $headers, $request_body, false, true), true);
        $this->log->log('Результат объединения: ' . json_encode($merge_result, JSON_UNESCAPED_UNICODE));
        $response = $merge_result['response'] ?? [];
        if (count($response)){
            if (isset($response['multiactions']['set']['errors']) && !count($response['multiactions']['set']['errors'])) {
                $this->log->log('Успешное объединение: ' . json_encode($response['multiactions']));
            } elseif (++$this->merge_attempts < MERGE_ATTEMPTS) {
                $this->log->log('Ошибка при объединении, пробуем повторный запрос: ' . json_encode($response['multiactions']['set']['errors']));
                sleep(SECONDS_FOR_SLEEP);
                $this->mergeLeads($request_body);
            } else {
                $this->log->log('Сбой при объединении после ' . $this->merge_attempts . ' попыток, завершение работы');
                try {
                    $this->db->truncate_processed($this);
                } catch (Exception $error) {
                    $this->badlog->log($error->getMessage());
                }
                die();
            }
        } elseif (++$this->merge_attempts < MERGE_ATTEMPTS) {
            $this->log->log('Ошибка при объединении, пробуем повторный запрос');
            sleep(SECONDS_FOR_SLEEP);
            $this->mergeLeads($request_body);
        } else {
            $this->log->log('Сбой при объединении после ' . $this->merge_attempts . ' попыток, завершение работы');
            try {
                $this->db->truncate_processed($this);
            } catch (Exception $error) {
                $this->badlog->log($error->getMessage());
            }
            die();
        }
        $this->merge_attempts = 0;
        return true;
    }

    /**
     * @param $body
     * @return array
     */
    public function creatingContacts($body): array
    {
        $amo_link = 'https://' . SUBDOMAIN . '.amocrm.ru'.'/api/v4/contacts';
        $headers = ['Authorization: Bearer ' . $this->client->access_token->getToken(), 'Content-Type: application/json'];
        $this->retry = 0;
        $response = json_decode($this->makeRequest('POST', $amo_link, $headers, $body, true, false), true);
        return $response['_embedded']['contacts'] ?? [];
    }

    /**
     * @param $body
     * @return array
     */
    public function creatingLeads($body): array
    {
        $link = 'https://' . SUBDOMAIN . '.amocrm.ru'.'/api/v4/leads';
        $headers = ['Authorization: Bearer ' . $this->client->access_token->getToken(), 'Content-Type: application/json'];
        $this->retry = 0;
        $response = json_decode($this->makeRequest('POST', $link, $headers, $body, true, false),true);
        return $response['_embedded']['leads'] ?? [];
    }

    /**
     * @param $body
     * @return array
     */
    public function creatingTasks($body): array
    {
        $link = 'https://' . SUBDOMAIN . '.amocrm.ru'.'/api/v4/tasks';
        $headers = ['Authorization: Bearer ' . $this->client->access_token->getToken(), 'Content-Type: application/json'];
        $this->retry = 0;
        $response = json_decode($this->makeRequest('POST', $link, $headers, $body, true, false),true);
        return $response['_embedded']['tasks'] ?? [];
    }

    /**
     * @param array $request_body
     * @return array
     */
    public function getContactsFront(array $request_body = []): array
    {
        $front_link = 'https://' . BASE_DOMAIN . '/ajax/contacts/list/';
        $method = 'POST';
        $headers = ['Authorization: Bearer ' . $this->getToken(), 'X-Requested-With: XMLHttpRequest', 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8'];
        $this->retry = 0;
        $response = json_decode($this->makeRequest($method, $front_link, $headers, $request_body, false, true),true);
        return $response['response'] ?? [];
    }

    /**
     * @param array $id
     * @return array
     */
    public function comparingLeads(array $id): array
    {
        $request_link = 'https://' . BASE_DOMAIN . '/ajax/merge/leads/info/';
        $headers = ['Authorization: Bearer ' . $this->getToken(), 'X-Requested-With: XMLHttpRequest', 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8'];
        $request_body = ['id' => $id];
        $this->retry = 0;
        $response = json_decode($this->makeRequest('POST', $request_link, $headers, $request_body, true), true);
        if (is_array($response)) {
            return $response['response'];
        } else {
            return [];
        }
    }


    /**
     * Отправка CURL запроса
     * @param string $method
     * @param string $link
     * @param array $headers
     * @param array|null $body
     * @param bool $amoagent
     * @param bool $amo_front
     * @return string
     */
    public function makeRequest(string $method, string $link, array $headers, array $body = null, bool $amoagent = false, bool $amo_front = false): string
    {
        $ch = curl_init();
        if (isset($body)) {
            $amo_front ? curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body)) : curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        if ($amoagent) curl_setopt($ch, CURLOPT_USERAGENT, 'amoCRM-oAuth-client/1.0');
        curl_setopt($ch, CURLOPT_URL, $link);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $response = curl_exec($ch);
        $this->response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // $this->log->log('Code ' . $this->response_code . ' from request to ' . $link);
        curl_close($ch);
//        if ($this->response_code === 200 || $this->response_code === 202 || $this->response_code === 204) {
            return (string)$response;
//        } elseif (!$this->response_code || $this->response_code >= 400) {
//            if ($this->retry >= ERROR_ATTEMPTS) {
//                $this->log->log('Не удалось отправить запрос после ' . $this->retry . ' попыток, выход');
//                die();
//            }
//            $this->log->log('Ошибка ' . $this->response_code . ', повторная попытка отправки запроса номер ' . ++$this->retry);
//            sleep(SECONDS_FOR_SLEEP);
//            $response = $this->makeRequest($method, $link, $headers, $body, $amoagent);
//        } else {
//            $this->log->log('Ошибка при отправке запроса');
//        }
//        return (string)$response;
    }
}