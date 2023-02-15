<?php
declare(strict_types=1);

namespace Airborne;

use Exception;
use PDO;
use PDOException;

class PDO_PG extends PDO
{

private Logger $log;
private Logger $badlog;

public function __construct()
{
    $this->log = new Logger(__DIR__ .'/../../logs/'.basename(__FILE__,'.php').'.log');
    $this->badlog = new Logger(__DIR__ .'/../../logs/'.basename(__FILE__,'.php').'Errors'.'.log');
    try {
        $dsn = 'pgsql:host=' .PGDB['host'].';port='.PGDB['port'].';dbname='.PGDB['db'];
        parent::__construct($dsn, PGDB['user'], PGDB['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (PDOException $e) {
        echo 'DB connect error: ',$e->getMessage();
        die();
    }
}

public function create_table(string $schema, string $table, array $fields_array, array $keys): bool
{
    if (!count($fields_array)) { $this->badlog->log('Got empty field array while creating new table, killing the process'); die(); }
    $fields = [];
    array_walk($fields_array, function($field) use (&$fields) { $fields[] = implode(' ',$field); });
    $fields_string = implode(', ', $fields);
    $keys_string = implode(', ', $keys);
    $sql = 'CREATE TABLE IF NOT EXISTS '.$schema.'.'.$table.' ('.$fields_string.' ,'.$keys_string.') ;';
    $stmt = $this->prepare($sql);
    return $stmt->execute();
}

public function select_from_table(string $table, array $fields_array = [], array $condition_array = [], string $order_field = '', string $order_direction = '', int $limit=0, string $group = ''): array
{
    $opts = [];
    count($fields_array) ? $fields = implode(', ', $fields_array) : $fields = '*';
    if ($order_field) {
        $order = 'ORDER BY '.$order_field;
    } else {
        $order = '';
    }
    if (strlen($group)) $group = ' GROUP BY '.$group;
    $limit ? $limit = ' LIMIT '.$limit : $limit = '';
    if (count($condition_array)) {
        $condition = ' WHERE ';
        array_walk($condition_array, function($value, $field) use (&$condition, &$opts) {
            if ($value === 'IS NOT NULL'){
                $condition .= $field .' '.$value.' AND ';
            } elseif ($value === 'IS NULL'){
                $condition .= $field .' '.$value.' AND ';
            } elseif (str_contains($value, '>=')){
                $condition .= $field .'>=:'.$field.' AND ';
                $opts[':'.$field] = trim($value,'>=');
            } else {
                $condition .= $field .'=:'.$field.' AND ';
                $opts[':'.$field] = $value;
            }
        });
        $condition = rtrim($condition,' AND');
    } else { $condition = ''; }
    $sql = 'SELECT '.$fields.' FROM '. $table . $condition . ' ' . $order . ' ' . $order_direction . $group . $limit.';';
    $stmt = $this->prepare($sql);
    $stmt->execute($opts);
    $result = $stmt->fetchAll();
    return is_array($result) ? $result : [];
}

public function insert_into_table(string $table, array $values_array, array $fields_array): bool
{
    $options = [];
    $query = 'INSERT INTO ' .$table. ' (' .implode(', ', $fields_array). ') VALUES ';
    array_walk($values_array, function($values, $data_key) use(&$query, &$options, $fields_array){
        $query .= '(';
        array_walk($fields_array, function($field) use (&$query, &$options, $data_key, $values){
            $query .= ':'.$field.'_'.$data_key.',';
            $options[':'.$field.'_'.$data_key] = $values[$field] ?? null;
        });
        $query = rtrim($query, ',');
        $query .= '),';
    });
    $statement = $this->prepare(rtrim($query, ','));
    try {
        return $statement->execute($options);
    } catch (PDOException $error) {
        $this->badlog->log('Error on INSERT INTO '.$table.' : '.$error->getMessage());
    }
    return false;
}

    /**
     * @param string $schema
     * @param string $table
     * @param string $column
     * @return bool
     */
    public function alterTableAddCol(string $schema, string $table, array $columns): bool
{
    $query = 'ALTER TABLE IF EXISTS '.$schema.'.'.$table.' ADD COLUMN IF NOT EXISTS '. implode(', ADD COLUMN IF NOT EXISTS ',$columns).';';
    $statement = $this->prepare($query);
    return $statement->execute();
}

public function update_table(string $table, array $records, string $condition_column, array $updated_columns): bool
{
    $opts = [];
    $sql = 'UPDATE '.$table.' AS main SET '.$updated_columns[0].' = temp.'.$updated_columns[0].' FROM (VALUES ';
    array_walk($records, function($record, $key) use (&$opts, &$sql, $condition_column, $updated_columns){
        $sql.='(:'.$condition_column.'_'.$key.', :'.$updated_columns[0].'_'.$key.'), ';
        $opts[':'.$condition_column.'_'.$key] = $record[$condition_column];
        $opts[':'.$updated_columns[0].'_'.$key] = $record[$updated_columns[0]];
    });
    $sql = rtrim($sql, ', ');
    $sql.=') AS temp('.$condition_column.', '.$updated_columns[0].') WHERE temp.'.$condition_column.' = main.'.$condition_column.';';
    $stmt = $this->prepare($sql);
    return $stmt->execute($opts);
}

/**
 * Возвращает размер таблицы
 * @param string $table
 * @return int
 */
public function getSize(string $table): int
{
    $statement = $this->prepare('SELECT COUNT(*) FROM ' .$table.";");
    $statement->execute();
    $fetched = $statement->fetchAll();
    return count($fetched) ? $fetched[0]['count'] : 0;
}

    /**
     * Добавляет пакетно полученные из контактов телефоны и почты вместе с id контактов в таблицы для поиска дублей
     * @param DuplicateProcessor $merger
     * @param array $contacts
     * @param array $phones
     * @param array $emails
     * @param int $time
     * @param bool $new
     * @return array
     */
public function insert_batch(DuplicateProcessor $merger, array $contacts, array $phones, array $emails, int $time, bool $new = false): array
{
    if ($new && (count($phones) || count($emails))) {
        $new_phones = array_column($phones, 'id') ?? [];
        $new_emails = array_column($emails, 'id') ?? [];
        $new_records = array_unique(array_merge($new_phones, $new_emails));
        $this->delete_records($new_records, null);
    }
    $phones_db_size_after = $phones_db_size_before = $this->getSize(PHONES_TABLE);
    if (count($phones)){
        $this->replace_into_table(PHONES_TABLE, $phones, ['id', 'phone', 'last_mod', 'responsible_user_id', 'linked_leads', 'last_time'], 'phone', $time);
        $phones_db_size_after = $this->getSize(PHONES_TABLE);
    }
    $emails_db_size_after = $emails_db_size_before = $this->getSize(EMAILS_TABLE);
    if (count($emails)){
        $this->replace_into_table(EMAILS_TABLE, $emails, ['id', 'email', 'last_mod', 'responsible_user_id', 'linked_leads', 'last_time'], 'email',$time);
        $emails_db_size_after = $this->getSize(EMAILS_TABLE);
    }
    $sql = "INSERT INTO ".LOG_TABLE." VALUES ('".$merger->offset*AMO_MAX_LIMIT."',:phones,:emails,
            '".$time."','".$merger->contacts_received."','".count($phones)."','".count($emails)."',
            '".$phones_db_size_before."','".$phones_db_size_after."','".$emails_db_size_before."','".$emails_db_size_after."');";
    $opts = [':phones' => json_encode($phones,JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
        ':emails' => json_encode($emails,JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)];
    $stmt = $this->prepare($sql);
    try {
        $stmt->execute($opts);
        return [];
    } catch (PDOexception $error) {
        $this->log->log('Не удалось записать данные в таблицу, ошибка '.$error->getMessage());
        $this->log->log(json_encode(['offset'=>$merger->offset*AMO_MAX_LIMIT, 'phones'=>$phones, 'emails'=>$emails, 'received'=>$merger->contacts_received,
            'phones_before'=>$phones_db_size_before, 'phones_after'=>$phones_db_size_after, 'emails_before'=>$emails_db_size_before, 'emails_after'=>$emails_db_size_after
        ], JSON_UNESCAPED_UNICODE));
    }
    return [];
}

    /**
     * @param DuplicateProcessor $merger
     * @param array $phones
     * @param array $emails
     * @param array $leads
     * @param int $time
     * @param bool $new
     * @return array
     */
    public function insertTestBatch(DuplicateProcessor $merger, array $phones, array $emails, array $leads, int $time, bool $new = false): array
{
    if ($new && (count($phones) || count($emails))) {
        $new_phones = array_column($phones, 'id') ?? [];
        $new_emails = array_column($emails, 'id') ?? [];
        $new_records = array_unique([...$new_phones, ...$new_emails]);
        $this->delete_records($new_records, null);
    }
    if (count($leads)){
        $replacing_leads = [];
        array_walk($leads, function($lead) use (&$replacing_leads, $time) { $replacing_leads[] = ['id'=>$lead, 'last_time'=>$time];});
        $this->replaceIntoLeads(LEADS_TABLE, $replacing_leads, ['id', 'last_time']);
    }
    $phones_size_from = $phones_size_till = $this->getSize(TEST_PHONES_TABLE);
    if (count($phones)){
        $this->replaceIntoTestTable(TEST_PHONES_TABLE, $phones, ['rec_id', 'id', 'phone', 'last_mod', 'responsible_user_id', 'lead_id', 'last_time'], 'phone', $time);
        $phones_size_till = $this->getSize(TEST_PHONES_TABLE);
    }
    $emails_size_from = $emails_size_till = $this->getSize(TEST_EMAILS_TABLE);
    if (count($emails)){
        $this->replaceIntoTestTable(TEST_EMAILS_TABLE, $emails, ['rec_id', 'id', 'email', 'last_mod', 'responsible_user_id', 'lead_id', 'last_time'], 'email',$time);
        $emails_size_till = $this->getSize(TEST_EMAILS_TABLE);
    }
        $query = 'INSERT INTO ' .LOG_TABLE." VALUES ('".$merger->offset*AMO_MAX_LIMIT."',:phones,:emails,
                '".$time."','".$merger->contacts_received."','".count($phones)."','".count($emails)."',
                '".$phones_size_from."','".$phones_size_till."','".$emails_size_from."','".$emails_size_till."');";
        $opts = [':phones' => json_encode($phones,JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
            ':emails' => json_encode($emails,JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)];
        $statement = $this->prepare($query);
        try {
            if ($statement->execute($opts)) return [];
        } catch (PDOException $error) {
            $this->badlog->log('Не удалось записать данные в таблицу, ошибка '.$error->getMessage());
            $this->badlog->log(json_encode(['offset'=>$merger->offset*AMO_MAX_LIMIT, 'phones'=>$phones, 'emails'=>$emails, 'received'=>$merger->contacts_received,
                'phones_before'=>$phones_db_size_before, 'phones_after'=>$phones_db_size_after, 'emails_before'=>$emails_db_size_before, 'emails_after'=>$emails_db_size_after
            ], JSON_UNESCAPED_UNICODE));
            die();
        }
    return [];
}

    /**
     * @param string $table
     * @param array $values
     * @param array $fields_array
     * @param string $value_name
     * @param int $time
     * @return bool
     */
    public function replaceIntoTestTable(string $table, array $values, array $fields_array, string $value_name, int $time): bool
{
    $options = [];
    $query = 'INSERT INTO ' .$table. ' (' .implode(', ', $fields_array). ') VALUES ';
    array_walk($values, function($item, $key) use(&$query, &$options, $time, $value_name){
        $query .= '(:rec_id_' .$key. '_' .$item['rec_id']. ',:id_' .$key. '_' .$item['id']. ',:value_' .$key. '_' .$item['id']. ',:last_mod_' .$item['id']. ',:responsible_user_id_' .$item['id']. ',:lead_id_'.$key. '_' .$item['id']. ",'" .$time. "'),";
        $options[':rec_id_'.$key.'_'.$item['rec_id']] = $item['rec_id'];
        $options[':id_'.$key.'_'.$item['id']] = $item['id'];
        $options[':value_'.$key.'_'.$item['id']] = $item[$value_name];
        $options[':last_mod_'.$item['id']] = $item['last_mod'];
        $options[':responsible_user_id_'.$item['id']] = $item['responsible_user_id'];
        $options[':lead_id_'.$key.'_'.$item['id']] = $item['lead_id'] ?? null;
    });
    $query = rtrim($query, ',');
    $query .= ' ON CONFLICT (rec_id,' .$value_name. ',id) DO UPDATE SET rec_id = EXCLUDED.rec_id, id = EXCLUDED.id, ' .$value_name. ' = EXCLUDED.' .$value_name. ',last_mod = EXCLUDED.last_mod,'.
        'responsible_user_id = EXCLUDED.responsible_user_id, lead_id = EXCLUDED.lead_id, last_time = EXCLUDED.last_time;';
    $statement = $this->prepare($query);
    try {
        return $statement->execute($options);
    } catch (PDOException $error) {
        $this->badlog->log('Не удалось записать данные в таблицу, ошибка '.$error->getMessage());
        $this->badlog->log(json_encode($values, JSON_UNESCAPED_UNICODE));
        die();
    }
}

/**
 * Вставка новых записей с заменой совпадающих
 * @param string $table
 * @param array $data
 * @param array $fields_array
 * @param string $value_name
 * @param int $time
 * @return bool
 */
private function replace_into_table(string $table, array $data, array $fields_array, string $value_name, int $time): bool
{
    $opts = [];
    $sql = 'INSERT INTO ' .$table. ' (' .implode(', ', $fields_array). ') VALUES ';
    array_walk($data, function($item, $key) use(&$sql, &$opts, $time, $value_name){
        $sql .= '(:id_' .$key. '_' .$item['id']. ',:value_' .$key. '_' .$item['id']. ',:last_mod_' .$item['id']. ',:responsible_user_id_' .$item['id']. ',:linked_leads_' .$item['id']. ",'" .$time. "'),";
        $opts[':id_'.$key.'_'.$item['id']] = $item['id'];
        $opts[':value_'.$key.'_'.$item['id']] = $item[$value_name];
        $opts[':last_mod_'.$item['id']] = $item['last_mod'];
        $opts[':responsible_user_id_'.$item['id']] = $item['responsible_user_id'];
        $opts[':linked_leads_'.$item['id']] = $item['linked_leads'] ?? null;
    });
    $sql = rtrim($sql, ',');
    $sql .= ' ON CONFLICT (' .$value_name. ',id) DO UPDATE SET id = EXCLUDED.id, ' .$value_name. ' = EXCLUDED.' .$value_name. ', 
        last_mod = EXCLUDED.last_mod, responsible_user_id = EXCLUDED.responsible_user_id, linked_leads = EXCLUDED.linked_leads, last_time = EXCLUDED.last_time;';
    $stmt = $this->prepare($sql);
    try {
        return $stmt->execute($opts);
    } catch (PDOException $error) {
        $this->badlog->log('Не удалось записать данные в таблицу, ошибка '.$error->getMessage());
        $this->badlog->log(json_encode($data, JSON_UNESCAPED_UNICODE));
        die();
    }
}

    /**
     * @param string $table
     * @param array $values
     * @param array $fields_array
     * @param array $options
     * @return bool
     */
public function replaceIntoLeads(string $table, array $values, array $fields_array, array $options = []): bool
{
    if(!count($values) || !(count($fields_array)) || !strlen($table)) return false;
    $query = 'INSERT INTO ' .$table. ' (' .implode(', ', $fields_array). ') VALUES ';
    array_walk($values, function($value, $value_key) use(&$query, &$options, $fields_array){
            $query .= '(';
        array_walk($fields_array, function($field) use ($value_key, $value, &$query, &$options){
            $query .= ':'.$field.'_'.$value_key.', ';
            $options[':'.$field.'_'.$value_key] = $value[$field];
        });
        $query = rtrim($query, ', ') .'),';
    });
    $query = rtrim($query, ',') .' ON CONFLICT (id) DO UPDATE SET';
    array_walk($fields_array, function($field) use (&$query){
        $query .= ' '.$field.' = EXCLUDED.'.$field.',';
    });
    $query = rtrim($query, ',') .';';
    $statement = $this->prepare($query);
    try {
        return $statement->execute($options);
    } catch (PDOException $error) {
        $this->badlog->log('Не удалось записать данные в таблицу, ошибка '.$error->getMessage());
        $this->badlog->log(json_encode($values, JSON_UNESCAPED_UNICODE));
        die();
    }
}

/**
 * Добавление с заменой записей в таблицу обработанных дублей и контактов
 * @param string $table
 * @param array $data
 * @param array $fields_array
 * @return bool
 */
public function replace_into_processed(string $table, array $data, array $fields_array): bool
{
    $opts = [];
    $sql = "INSERT INTO ".$table." (".implode(', ', $fields_array).") VALUES ";
    array_walk($data, function($item) use(&$sql, &$opts){
        $sql .= "(:id_".$item."),";
        $opts[':id_'.$item] = $item;
    });
    $sql = rtrim($sql, ',');
    $sql .= " ON CONFLICT (id) DO UPDATE SET id = EXCLUDED.id;";
    $stmt = $this->prepare($sql);
    try {
        return $stmt->execute($opts);
    } catch (PDOexception $error) {
        $this->badlog->log('Не удалось записать данные в таблицу, ошибка '.$error->getMessage());
        $this->badlog->log(json_encode($data, JSON_UNESCAPED_UNICODE));
        return false;
    }
}

/**
 * Поиск дублей по телефонам и почтам, объединение подмножеств дублей
 * @param bool $continue
 * @return array
 */
public function select_duplicates(bool $continue = false): array
{
    if ($continue){
        $sql = "SELECT * FROM ".DUPLICATES_TABLE.";";
        $stmt = $this->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    $stmt = $this->prepare("SELECT phone, STRING_AGG (DISTINCT id::varchar, ',') as contacts_id, COUNT(DISTINCT id) FROM ".TEST_PHONES_TABLE." GROUP BY phone HAVING COUNT(DISTINCT id)>1;");
    $stmt->execute();
    $result = $stmt->fetchAll() ?? [];
    $result = array_map(function($item){return explode(',',$item['contacts_id']);},$result);
    $duplicates['phones'] = $result;
    $stmt = $this->prepare("SELECT email, STRING_AGG (DISTINCT id::varchar, ',') as contacts_id, COUNT(DISTINCT id) FROM ".TEST_EMAILS_TABLE." GROUP BY email HAVING COUNT(DISTINCT id)>1;");
    $stmt->execute();
    $result = $stmt->fetchAll() ?? [];
    $result = array_map(function($item){return explode(',',$item['contacts_id']);},$result);
    $duplicates['emails'] = $result;
    return $this->duplicates_array_search($duplicates);
    // return $this->duplicates_fragmented_search($duplicates);
}

/**
 * Поиск дублей через таблицы с рекурсией
 * @param array $duplicates
 * @return array
 */
private function duplicates_fragmented_search(array $duplicates): array
{
    $phones_duplicates_length = count($duplicates['phones']);
    //$this->log->log('Количество дублей по телефонам: ' . $phones_duplicates_length);
    $emails_duplicates_length = count($duplicates['emails']);
    //$this->log->log('Количество дублей по почтам: ' . $emails_duplicates_length);
    if (!$phones_duplicates_length && !$emails_duplicates_length){
        //$this->log->log('Дублей не найдено');
        return [];
    }
    //$this->log->log('Копирование дублей в промежуточную таблицу');
    if ($phones_duplicates_length){
        $duplicates['phones'] = array_chunk($duplicates['phones'], 10000);
        foreach ($duplicates['phones'] as $current_chunk){
            $opts = [];
            $counter = 0;
            $sql = "INSERT INTO ".DUPLICATES_PROXY_TABLE." (duplicates) VALUES ";
            array_map(function($duplicate) use(&$sql, &$opts, &$counter){
                $sql .= "(:duplicate_".$counter."),";
                $opts[':duplicate_'.$counter] = json_encode($duplicate);
                ++$counter;
            },$current_chunk);
            $stmt = $this->prepare(rtrim($sql, ','));
            $stmt->execute($opts);
        }
    }
    if ($emails_duplicates_length){
        $duplicates['emails'] = array_chunk($duplicates['emails'], 10000);
        foreach ($duplicates['emails'] as $current_chunk){
            $opts = [];
            $counter = 0;
            $sql = "INSERT INTO ".DUPLICATES_PROXY_TABLE." (duplicates) VALUES ";
            array_map(function($duplicate) use(&$sql, &$opts, &$counter){
                $sql .= "(:duplicate_".$counter."),";
                $opts[':duplicate_'.$counter] = json_encode($duplicate);
                ++$counter;
            },$current_chunk);
            $stmt = $this->prepare(rtrim($sql, ','));
            $stmt->execute($opts);
        }
    }
    //$this->log->log('Объединение подмножеств дублей');
    $sql = "SELECT * FROM ".DUPLICATES_PROXY_TABLE." ORDER BY id;";
    $outer = $this->prepare($sql);
    $outer->execute();
    $base_duplicate = $outer->fetch();
    while($base_duplicate) {
        $id_proxy_to_delete = $base_duplicate['id'];
        $duplicates = json_decode($base_duplicate['duplicates'],true);
        $sql = "SELECT * FROM ".DUPLICATES_PROXY_TABLE."  WHERE id > :id_proxy_to_delete ORDER BY id;";
        $inner = $this->prepare($sql);
        $_ = $inner->execute([':id_proxy_to_delete' => $id_proxy_to_delete]);
        $checked = $inner->fetch();
        $updated = false;
        while($checked) {
            $checked_duplicates = json_decode($checked['duplicates'],true);
            if (array_intersect($checked_duplicates, $duplicates)) {
                $duplicates_to_update = array_unique(array_merge($checked_duplicates, $duplicates));
                $sql = "UPDATE ".DUPLICATES_PROXY_TABLE." SET duplicates = :duplicates_to_update WHERE id = :checked_id;" ;
                $stmt = $this->prepare($sql);
                $updated = $stmt->execute([':duplicates_to_update' => json_encode(array_values($duplicates_to_update)), ':checked_id' => $checked['id']]);
                $_ = $this->deleteFromTable(DUPLICATES_PROXY_TABLE, 'id', [$id_proxy_to_delete]);
                break;
            }
            $checked = $inner->fetch();
        };
        if ($updated) {
            $sql = "SELECT * FROM ".DUPLICATES_PROXY_TABLE."  WHERE id > :id_proxy_to_delete ORDER BY id;";
            $outer = $this->prepare($sql);
            $_ = $outer->execute([':id_proxy_to_delete' => $id_proxy_to_delete]);
        }
        $base_duplicate = $outer->fetch();
    }
    $sql = "INSERT INTO ".DUPLICATES_TABLE." SELECT * FROM ".DUPLICATES_PROXY_TABLE.";";
    $stmt = $this->prepare($sql);
    $stmt->execute();
    $sql = "SELECT * FROM ".DUPLICATES_TABLE.";";
    $stmt = $this->prepare($sql);
    $stmt->execute();
    $duplicates =  $stmt->fetchAll();
    //$this->log->log('Всего дублей: '.count($duplicates));
    return $duplicates;
}

/**
 * Объединение подмножеств дублей через массив
 * @param array $duplicates
 * @return array
 */
private function duplicates_array_search(array $duplicates): array
{
    $duplicates_all =
        //array_merge(
        $duplicates['phones']
        //, $duplicates['emails'])
    ;
    $duplicates_length = count($duplicates_all);
    if ($duplicates_length){
        //$this->log->log('Количество дублей до объединения: ' . $duplicates_length);
        //$this->log->log('Объединение подмножеств дублей');
        $outer = 0;
        while($outer<$duplicates_length){
            $inner = $outer+1;
            while($inner<$duplicates_length){
                if (count(array_intersect($duplicates_all[$outer], $duplicates_all[$inner]))) {
                    $duplicates_all[$inner] = array_values(array_unique(array_merge($duplicates_all[$outer], $duplicates_all[$inner])));
                    unset($duplicates_all[$outer]);
                    break;
                }
                ++$inner;
            }
            //if (!($outer%100)) $this->log->log($outer);
            ++$outer;
        }
        $duplicates_all = array_merge($duplicates_all, []);
        //$this->log->log('Количество дублей после объединения (будут вставлены в таблицу дублей): '. count($duplicates_all));
        $opts = [];
        $counter = 0;
        $sql = "INSERT INTO ".DUPLICATES_TABLE." (id, duplicates) VALUES ";
        array_map(function($duplicate) use(&$sql, &$opts, &$counter){
            $sql .= "(:id_".$counter.",:duplicate_".$counter."),";
            $opts[':duplicate_'.$counter] = json_encode($duplicate);
            $opts[':id_'.$counter] = $counter;
            ++$counter;
        },$duplicates_all);
        $stmt = $this->prepare(rtrim($sql, ','));
        $stmt->execute($opts);
    }
    return $duplicates_all;
}

/**
 * Функция очистки БД от обработанных дублей и контактов
 * @return void
 * @throws Exception
 */
public function truncate_processed(DuplicateProcessor $merger)
{
    $duplicates = false;
    $phones = false;
    $emails = false;
    $truncate = false;
    $query = 'SELECT * FROM ' .PROCESSED_DUPLICATES_TABLE. ';';
    $statement = $this->prepare($query);
    $statement->execute();
    $processed = array_column($statement->fetchAll(),'id');
    if (count($processed)) {
        $duplicates = $this->deleteFromTable(DUPLICATES_TABLE, 'id', $processed);
        //$duplicates ? $this->log->log('Обработанные дубли удалены') : $this->log->log('Ошибка при удалении обработанных дублей');
    }
    $query = 'SELECT * FROM ' .PROCESSED_CONTACTS_TABLE. ';';
    $statement = $this->prepare($query);
    $statement->execute();
    $processed = array_column($statement->fetchAll(),'id');
    if (count($processed)) {
        $processed = array_map('intval', $processed);
        $phones = $this->deleteFromTable(TEST_PHONES_TABLE, 'id', $processed);
        $emails = $this->deleteFromTable(TEST_EMAILS_TABLE, 'id', $processed);
        $trash_updated = $merger->cache->get($merger->prefix . '_trash');
        $updated_from = $this->getLatest([TEST_PHONES_TABLE, TEST_EMAILS_TABLE]);
        $deadline = max($trash_updated, $updated_from);
        $new_deadline = time();
        $merger->db_trash = $merger->getEvents(['limit'=>100,'filter'=>['entity'=>['contact'],'type'=>['contact_deleted'],'created_at'=>['from'=>$deadline]]]);
        if ($trash = count($merger->db_trash)) {
            $merger->cache->set($merger->prefix . '_trash', $new_deadline, 31536000 );
            $trashed = array_column($merger->db_trash, 'entity_id');
            $trashed = array_diff($trashed, $processed);
            if ($trashed) $this->purgeTrash($trashed, false, false);
            $merger->db_trash = [];
        } else {
            $this->log->log('Удаленных контактов нет');
        }
    }
    if ($duplicates) $truncate = $this->prepare('TRUNCATE ' .PROCESSED_DUPLICATES_TABLE. ' RESTART IDENTITY;')->execute();
    if ($phones && $emails) $truncate &= $this->prepare('TRUNCATE ' .PROCESSED_CONTACTS_TABLE. ' RESTART IDENTITY;')->execute();
    if ($duplicates || ($phones && $emails)) {
        $truncate ? $this->log->log('Таблицы с обработанными дублями и контактами очищены') : $this->log->log('Таблицы с обработанными дублями и контактами не были очищены');
    } else {
        $this->log->log('Таблицы с обработанными дублями и контактами пустые');
    }
}

/**
 * Удаление записей из таблиц (обработанных дублей и контактов, а также обновленных контактов)
 * @param array $duplicates
 * @param int|null $duplicate_db_id
 * @return void
 */
public function delete_records(array $duplicates, int $duplicate_db_id = null)
{
    if ($duplicate_db_id !== null) {
        $this->deleteFromTable(DUPLICATES_TABLE, 'id', [$duplicate_db_id]); $this->log->log('Из таблицы дублей удален дубль # '. $duplicate_db_id);}
    if (count($duplicates)) {
        $this->deleteFromTable(TEST_PHONES_TABLE, 'id', $duplicates);
        $this->deleteFromTable(TEST_EMAILS_TABLE, 'id', $duplicates);
        $this->log->log('Из таблиц телефонов и почт удалены id: # ' . json_encode($duplicates));
    } else {
        $this->log->log('Нет контактов для удаления из таблиц телефонов и почт - все с неразобранным');
    }
}

/**
 * Удаление записей из таблиц
 * @param string $table
 * @param string $match_field
 * @param array $match_data
 * @return bool
 */
public function deleteFromTable(string $table, string $match_field, array $match_data): bool
{
    list($query, $options) = ['DELETE FROM ' .$table. ' WHERE ' .$match_field. ' IN (',[]];
    array_map(function($item) use(&$query, &$options, $match_field){
        $query .= ':' .$match_field. '_' .$item. ',';
        $options[':'.$match_field.'_'.$item] = $item;
    },$match_data);
    $statement = $this->prepare(rtrim($query, ',').')');
    return $statement->execute($options);
}

/**
 * Полная очистка таблиц в БД
 * @param bool $only_duplicates
 * @return bool
 */
public function truncateData(bool $only_duplicates=false): bool
{
    $result = $this->prepare('TRUNCATE TABLE ' .DUPLICATES_TABLE. ' RESTART IDENTITY;')->execute();
    $result &= $this->prepare('TRUNCATE ' .DUPLICATES_PROXY_TABLE. ';')->execute();
    $result ? $this->log->log('Таблицы дублей очищены') : $this->log->log('Ошибка при удалении данных из таблиц дублей');
    if ($only_duplicates) return !!$result;
    $tables = [EMAILS_TABLE,PHONES_TABLE,LEADS_TABLE,TEST_EMAILS_TABLE,TEST_PHONES_TABLE,PROCESSED_DUPLICATES_TABLE,PROCESSED_CONTACTS_TABLE,LOG_TABLE];
    $result &= $this->prepare('TRUNCATE TABLE ' .implode(', ',$tables). ' RESTART IDENTITY;')->execute();
    $result ? $this->log->log('Данные удалены из таблиц') : $this->log->log('Ошибка при удалении данных из таблиц');
    return !!$result;
}

/**
 * Возвращает дату последней загрузки в БД
 * @param array $result
 * @param array $tables
 * @return int
 */
public function getLatest(array $tables, array $result = []): int
{
    array_walk($tables, function($table) use (&$result){
        $statement = $this->prepare('SELECT last_time FROM ' .$table. ' ORDER BY last_time DESC LIMIT 1;');
        $statement->execute();
        $result = [...$result, ...$statement->fetchAll()];
    });
    if (count($result)) return array_values(max($result))[0];
    return 0;
}

/**
 * Возвращает последний по времени измененный контакт (id)
 * @param array $contacts_id
 * @return string
 */
public function get_last_mod(array $contacts_id): string
{
    $result = [];
    $opts = [];
    $sql = 'SELECT id, last_mod FROM ' .PHONES_TABLE. ' WHERE id IN (';
    array_map(function($id) use(&$sql, &$opts){
        $sql .= ":id_".$id.",";
        $opts[':id_'.$id] = $id;
    },$contacts_id);
    $sql = rtrim($sql, ',').") ORDER BY last_mod DESC LIMIT 1;";
    $stmt = $this->prepare($sql);
    $stmt->execute($opts);
    $result = array_merge($result, $stmt->fetchAll());

    $opts = [];
    $sql = "SELECT id, last_mod FROM ".EMAILS_TABLE." WHERE id IN (";
    array_map(function($id) use(&$sql, &$opts){
        $sql .= ":id_".$id.",";
        $opts[':id_'.$id] = $id;
    },$contacts_id);
    $sql = rtrim($sql, ',').") ORDER BY last_mod DESC LIMIT 1;";
    $stmt = $this->prepare($sql);
    $stmt->execute($opts);
    $result = max(array_column(array_merge($result, $stmt->fetchAll()), 'id'));
    if ($result) return strval($result);
    return '';
}

/**
 * Очищает БД от телефонов и почт из удалённых контактов, а также очищает таблицу дублей от удалённых контактов
 * @param array $contacts
 * @param bool $new
 * @param bool $continue
 * @param array $tables
 * @return void
 */
public function purgeTrash(array $contacts, bool $new, bool $continue, array $tables = [TEST_PHONES_TABLE, TEST_EMAILS_TABLE]): void
{
    array_walk($tables, function($table) use (&$options, &$query, &$contacts) {
        $query = 'DELETE FROM ' .$table. ' WHERE id IN (';
        array_walk($contacts, function($contact) use(&$query, &$options){
            $query .= ':id_' .$contact. ',';
            $options[':id_'.$contact] = $contact;
        });
        $query = rtrim($query, ',').')';
        $statement = $this->prepare($query);
        $statement->execute($options);
    });
    if ($new) return;

    $query = 'SELECT * FROM ' .DUPLICATES_TABLE. ';';
    $statement = $this->prepare($query);
    $statement->execute();
    $duplicates = $statement->fetchAll();
    $replace = [];
    $delete = [];
    array_walk($duplicates, function($duplicate) use ($contacts, &$replace, &$delete){
        if (array_intersect(json_decode($duplicate['duplicates'],true), $contacts)) {
            $purged = array_values(array_diff(json_decode($duplicate['duplicates'],true), $contacts));
            count($purged)>1 ? $replace[] = ['id' => $duplicate['id'],'duplicates' => json_encode($purged)] : $delete[] = $duplicate['id'];
        }
    });

    if (count($replace)){
        $options = [];
        $query = 'INSERT INTO ' .DUPLICATES_TABLE. ' (id, duplicates) VALUES ';
        array_map(function($duplicate) use(&$query, &$options){
            $query .= '(:id_' .$duplicate['id']. ',:duplicates_' .$duplicate['id']. '),';
            $options[':id_'.$duplicate['id']] = $duplicate['id'];
            $options[':duplicates_'.$duplicate['id']] = $duplicate['duplicates'];
        },$replace);
        $query = rtrim($query, ',');
        $query .= ' ON CONFLICT (id) DO UPDATE SET id = EXCLUDED.id, duplicates = EXCLUDED.duplicates;';
        $statement = $this->prepare($query);
        $statement->execute($options);
    }

    if (count($delete)){
        $options = [];
        $query = 'DELETE FROM ' .DUPLICATES_TABLE. ' WHERE id IN (';
        array_map(function($duplicate) use(&$query, &$options){
            $query .= ':id_' .$duplicate. ',';
            $options[':id_'.$duplicate] = $duplicate;
        },$delete);
        $statement = $this->prepare(rtrim($query, ',').')');
        $statement->execute($options);
    }
    $this->log->log('Отсутствующие контакты удалены из таблиц');
}
}
