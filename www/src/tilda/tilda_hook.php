<?php
declare(strict_types=1);

namespace Airborne;

require_once __DIR__.'/../duplicates_controller/DuplicateProcessor.php';

require_once __DIR__.'/../init.php';
require_once __DIR__.'/TildaProcessor.php';
require_once __DIR__.'/../cache/Cache.php';

ini_set('error_log',__DIR__ .'/../../logs/phpErrors.log');
ini_set('log_errors', true);
ini_set('error_reporting', E_ALL);
set_time_limit(0);

echo('Ok');
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

$processor = new TildaProcessor();
$processor->logger->log('++++++++++START HOOK+++++++++++');
$result = 'No data received with hook';
$input = file_get_contents('php://input') ?? '';
if (count($_POST)){
    $processor->logger->log('Data in POST: '.json_encode($_POST));
    $result = $processor->process($_POST) ? 'Success' : 'Fail to process';
} elseif (strlen($input)){
    $input_data = json_decode($input,true) ?? null;
    if (is_array($input_data) && count($input_data)){
        $processor->logger->log('Data in input: '.json_encode($input_data));
        $result = $processor->process($input_data) ? 'Success' : 'Fail to process';
    }
}
if (count($_GET)){
    $processor->logger->log('Data in GET: '.json_encode($_GET));
}
