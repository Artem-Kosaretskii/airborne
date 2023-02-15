<?php
declare(strict_types=1);
const TOKEN_FILE = 'token_info.json';
const BUTTON_LINK = 'https://www.amocrm.ru/auth/button.min.js';
const HTTP_RESPONSE_ERRORS = [
    400 => 'Bad request',
    401 => 'Unauthorized',
    403 => 'Forbidden',
    404 => 'Not found',
    500 => 'Internal server error',
    502 => 'Bad gateway',
    503 => 'Service unavailable',
];
const MODULE_URL = 'https://api.modulbank.ru/v1/';
const MODULE_INFO = 'account-info';
const MODULE_OPS = 'operation-history';
const MODULE_N_RECORDS = 50;
const START_DATE = '2019-01-01 00:00:00';
const MODULE_FIELDS_ARRAY = [
    ['name'=>'id','type'=>'varchar(50)'],
    ['name'=>'company_id','type'=>'varchar(50)'],
    ['name'=>'status','type'=>'varchar(20)'],
    ['name'=>'category','type'=>'varchar(10)'],
    ['name'=>'name','type'=>'varchar(255)'],
    ['name'=>'inn','type'=>'varchar(12)'],
    ['name'=>'kpp','type'=>'varchar(9)'],
    ['name'=>'cntr_account','type'=>'varchar(20)'],
    ['name'=>'cntr_corr_account','type'=>'varchar(20)'],
    ['name'=>'bank_name','type'=>'varchar(255)'],
    ['name'=>'bic','type'=>'varchar(9)'],
    ['name'=>'currency','type'=>'varchar(5)'],
    ['name'=>'amount','type'=>'float'],
    ['name'=>'account','type'=>'varchar(20)'],
    ['name'=>'purpose','type'=>'varchar(1024)'],
    ['name'=>'executed','type'=>'timestamp'],
    ['name'=>'created','type'=>'timestamp'],
    ['name'=>'doc_number','type'=>'varchar(20)'],
    ['name'=>'card_id','type'=>'varchar(50)'],
    ['name'=>'lead_id','type'=>'varchar(15)'],
    ['name'=>'access_time','type'=>'timestamp'],
    ['name'=>'contract','type'=>'varchar(20)'],
    ['name'=>'note_id','type'=>'varchar(15)'],
];
const CONTRACT_TMPL = '(Дог|ДОГ|дог|Договор|ДОГОВОР|договор)(\S|)(\s*?|)(\S|номер|)(\s*?|)(\d+)';
const CONTRACT_FIELD = 688119;
const PAYMENT_NOTE_TITLE = '#Оплата';
const MAIN_USER_ID = 6056635;
const NOTES_CHUNK = 100;
const PRODUCT_PIPE = 5569777;
const PRODUCT_PIPE_STATUSES = [
    17 => ['id'=>89172570,'name'=>'Неразобранное'],
    0  => ['id'=>99173773,'name'=>'Звонок'],
    1  => ['id'=>89172976,'name'=>'Встреча'],
    2  => ['id'=>79882962,'name'=>'Переговоры'],
    4  => ['id'=>69881965,'name'=>'Договор'],
    6  => ['id'=>59880968,'name'=>'Счёт'],
    8  => ['id'=>29889961,'name'=>'Предоплата'],
    10 => ['id'=>79889934,'name'=>'Отгрузка'],
    11 => ['id'=>92072751,'name'=>'Доставка'],
    12 => ['id'=>19172779,'name'=>'Приёмка'],
    13 => ['id'=>69989977,'name'=>'Оплата'],
    14 => ['id'=>99890016,'name'=>'Расторгли договор'],
    15 => ['id'=>142,     'name'=>'Успешно реализовано'],
    16 => ['id'=>143,     'name'=>'Закрыто и не реализовано'],
];
const AMO_MAX_LIMIT = 250;
const MEMORY_LIMIT = '256M';
const CRON_OPTS = [
    'truncate_processed' => true,
    'duplicates_search' =>  false,
    'continue' =>           false,
    'load' =>               true,
    'new' =>                true,
    'merge' =>              100,
    'page' =>               null,
    'page_to' =>            null,
    'page_from' =>          null,
    'merge_leads' =>        false,
    'fixing_leads' =>       false,
    'truncate_after' =>     false,
    'truncate_before' =>    false,
    'truncate_duplicates'=> false,
];
const PHONES_TABLE = 'phones_table';
const EMAILS_TABLE = 'emails_table';
const LEADS_TABLE = 'leads_table';
const TEST_PHONES_TABLE = 'test_phones_table';
const TEST_EMAILS_TABLE = 'test_emails_table';
const DUPLICATES_TABLE = 'duplicates_table';
const DUPLICATES_PROXY_TABLE = 'duplicates_proxy_table';
const LOG_TABLE = 'duplicates_log_table';
const PROCESSED_CONTACTS_TABLE = 'processed_contacts_table';
const PROCESSED_DUPLICATES_TABLE = 'processed_duplicates_table';
const SECONDS_FOR_SLEEP = 3;
const ERROR_ATTEMPTS = 3;
const TIME_OFFSET = 3*60*60;
const TRASH_URL = '/ajax/contacts-trash/list/';
const UIS_LINK = 'https://dataapi.uiscom.ru/v2.0';
const MAX_NOTE_LENGTH = 20000;
const CHECK_STRING = '#Contacts merging#';
const NOTE_LENGTH_LIMIT = 10000;
const WORK_MIN = 120;
const MERGE_LIMIT = 5;
const MERGE_ATTEMPTS = 3;
const GET_MERGE_INFO_ATTEMPTS = 3;
const PHONE_FIELD_ID = 454949;
const EMAIL_FIELD_ID = 454951;
const CALLING_PIPELINE_STATUSES = [33213612, 33213617, 33213613, 28918616, 28918619, 28918612, 28918616, 28918878, 28911238, 29626232, 61887639];
const OTHER_SOURCES_PIPELINE_STATUSES = [28918282, 28918286, 28918288, 28911212, 28911217, 28911221, 28911226, 28911229, 28911232, 28911236];
const DESIGN_PIPELINE_STATUSES = [61687876, 61687878, 61687972, 61687977];
const MONTHS = [
    ['value'=>'Январь',     'id'=>542051],
    ['value'=>'Февраль',    'id'=>542053],
    ['value'=>'Март',       'id'=>542055],
    ['value'=>'Апрель',     'id'=>542057],
    ['value'=>'Май',        'id'=>542059],
    ['value'=>'Июнь',       'id'=>542091],
    ['value'=>'Июль',       'id'=>542093],
    ['value'=>'Август',     'id'=>542095],
    ['value'=>'Сентябрь',   'id'=>542097],
    ['value'=>'Октябрь',    'id'=>542099],
    ['value'=>'Ноябрь',     'id'=>542101],
    ['value'=>'Декабрь',    'id'=>542103],
];
const YEARS = [
    ['value'=>2019,     'id'=>917393],
    ['value'=>2020,     'id'=>352295],
    ['value'=>2021,     'id'=>822297],
    ['value'=>2022,     'id'=>192299],
];
const SOURCES = [
    ['id'=>832045,'value'=>'ТМ1'],
    ['id'=>832047,'value'=>'Входящая заявка / сайт'],
    ['id'=>832049,'value'=>'Входящая заявка / телефон'],
    ['id'=>832051,'value'=>'Вконтакте'],
    ['id'=>832053,'value'=>'Instagram'],
    ['id'=>832065,'value'=>'Входящая заявка / WhatsApp'],
    ['id'=>839357,'value'=>'Youtube'],
    ['id'=>839359,'value'=>'Реклама в группе Вконтакте'],
    ['id'=>839361,'value'=>'ТМ2'],
    ['id'=>839363,'value'=>'Неизвестная реклама'],
    ['id'=>842209,'value'=>'ТМ3'],
];
const XLS_KEYS = ['Клиент','Телефон','Канал','Статус','Дата','Ответственный',];
const XLS_ARRAY_KEYS = ['client','phone','source','status','date','responsible',];
const MONTHS_FIELD = 688885;
const YEARS_FIELD = 688887;
const HOUSING_FIELD = 688853;
const SPACE_FIELD = 685633;
const SOURCE_FIELD = 685631;
const CALLING_PIPELINE = 3296320;
const OTHER_SOURCES_PIPELINE = 5533393;
const COMPLEX_FIELD = 685625;
$constants = json_decode(file_get_contents('/.env'), true);
define('CLIENT_ID',$constants['client_id']);
define('SECRET',$constants['secret']);
define('SUBDOMAIN',$constants['subdomain']);
define('PGDB',$constants['pgdb']);
define('MODULE_ID',$constants['pgdb']);
define('MODULE_TOKEN',$constants['pgdb']);
define('SESSION_ID',$constants['pgdb']);
define('UIS_KEY',$constants['pgdb']);
define('UIS_APP_ID',$constants['pgdb']);

const REDIRECT_URI = 'https://'.SUBDOMAIN.'.com/auth.php';

const BASE_DOMAIN = SUBDOMAIN.'.amocrm.ru';
const MODULE_TABLE = 'module';
const CONTACT_BASE_URL = 'https://'. BASE_DOMAIN. '/contacts/detail/';
const TILDA_FIELDS = ['Name', 'Phone', 'tranid', 'COOKIES', 'formid'];
const TILDA_COOKIES_FIELDS = ['tildauid', '_ym_uid', '_ym_d', '_ym_isad', 'tildasid', '_ym_visorc', 'previousurl'];
const TILDA_DEFAULT_NAME = 'Безымянная заявка с сайта';
const TILDA_FIELDS_ARRAY = [
    ['name'=>'id','type'=>'serial', 'id'=>222222],
    ['name'=>'received','type'=>'integer', 'id'=>222222],
    ['name'=>'name','type'=>'varchar(1024)', 'id'=>222222],
    ['name'=>'phone','type'=>'varchar(50)', 'id'=>222222],
    ['name'=>'tranid','type'=>'varchar(256)', 'id'=>687071],
    ['name'=>'formid','type'=>'varchar(256)', 'id'=>687073],
    ['name'=>'tildauid','type'=>'varchar(256)', 'id'=>682222],
    ['name'=>'_ym_uid','type'=>'varchar(256)', 'id'=>682301],
    ['name'=>'_ym_d','type'=>'varchar(256)', 'id'=>682303],
    ['name'=>'_ym_isad','type'=>'varchar(256)', 'id'=>682305],
    ['name'=>'tildasid','type'=>'varchar(256)', 'id'=>682307],
    ['name'=>'_ym_visorc','type'=>'varchar(256)', 'id'=>682302],
    ['name'=>'previousurl','type'=>'varchar(256)', 'id'=>687075],
];
const TILDA_TABLE = 'tilda_table';
const TILDA_MAIN_RESPONSIBLE = 6475279;
const TILDA_CONTACT_TAG = ['name'=>'tilda', 'id'=>258901];
const TILDA_LEAD_TAG = ['name'=>'tilda', 'id'=>258899];
const TILDA_SOURCE_ID = 832047;
const TILDA_NOTICE = 'Новая заявка с сайта';
const UIS_WEBHOOKS_TABLE = 'uis_webhook_table';
const UIS_INCOMING_TABLE = 'uis_incoming_table';
const UIS_PHONES = [
    '74955556241'=>['private'=>1,       'internal'=>null,'id'=>null,  'sip'=>null    ],
    '79582343121'=>['private'=>79234532323,'internal'=>103,'id'=>6134579,'sip'=>'01425454'],
    '79581098327'=>['private'=>79052362346,'internal'=>102,'id'=>2341145,'sip'=> null    ],
    '79587171632'=>['private'=>79916343431,'internal'=>105,'id'=>7125556,'sip'=>'01054505'],
    '79589203451'=>['private'=>79154364362,'internal'=>104,'id'=>6613663,'sip'=>'04013245' ],
];
const UIS_EVENTS = ['call_ending','lost_call','incoming_call','outgoing_call'];
const UIS_NOTICE = 'Новая задача по входящему звонку ';
const UIS_MISSED = 'Пропущенный звонок с телефона ';
const UIS_MAIN_USER_ID = 12767632;
