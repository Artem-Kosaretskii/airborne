<?php
declare(strict_types=1);

namespace Airborne;

require_once __DIR__.'/../init.php';
require_once __DIR__.'/ScaleProcessor.php';

ini_set('error_log',__DIR__ .'/../../logs/phpErrors.log');
ini_set('log_errors', true);
ini_set('error_reporting', E_ALL);
set_time_limit(0);

$processor = new ScaleProcessor();
$processor->log->log('++++++++++START HOOK+++++++++++');
list($get_payments, $lead_id) = [null,null];
$input = file_get_contents('php://input') ?? '';
if (count($_POST)){
    $get_payments = $_POST['get_payments'] ?? null;
    $lead_id = $_POST['lead_id'] ?? null;
} elseif ($input){
    $input_data = json_decode($input,true) ?? null;
    if ($input_data){
        $get_payments = $input_data['get_payments'] ?? null;
        $lead_id = $input_data['lead_id'] ?? null;
    }
}
if ($get_payments && $lead_id){
    $payments_from_db = $processor->hookProcessing((int)$lead_id);
    echo $payments_from_db;
}
$processor->log->log('++++++++++FINISH HOOK++++++++++');
