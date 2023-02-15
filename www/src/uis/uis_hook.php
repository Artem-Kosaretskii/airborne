<?php
declare(strict_types=1);

use Airborne\UISController;

require_once __DIR__ . '/../init.php';
require_once __DIR__.'/UISController.php';
require_once __DIR__.'/../duplicates_controller/DuplicateProcessor.php';
require_once __DIR__.'./../cache/Cache.php';

ini_set('error_log',__DIR__ .'/../../logs/phpErrors.log');
ini_set('log_errors', true);
ini_set('error_reporting', E_ALL);
set_time_limit(0);

echo('Ok');
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

$uis_cont = new UISController();

// $uis_cont->logger->log('++++++++++START HOOK+++++++++++');
list($hook_data, $result) = [null,'No data received with hook'];
$input = file_get_contents('php://input') ?? '';
if (count($_POST)){
    // $uis_cont->logger->log('Data in POST: '.json_encode($_POST,JSON_UNESCAPED_UNICODE));
    $hook_data = $_POST;
} elseif (strlen($input)){
    $input_data = json_decode($input,true) ?? null;
    if (is_array($input_data) && count($input_data)){
        // $uis_cont->logger->log('Data in input: '.json_encode($input_data,JSON_UNESCAPED_UNICODE));
        $hook_data = $input_data;
    }
} elseif (count($_GET)){
    // $uis_cont->logger->log('Data in GET: '.json_encode($_GET,JSON_UNESCAPED_UNICODE));
    $hook_data = $_GET;
}
if ($hook_data) $result = $uis_cont->processHook($hook_data) ? 'Success' : 'Fail to process';
//$uis_cont->logger->log('++++++++++FINISH HOOK, result: '.$result.'++++++++++');
