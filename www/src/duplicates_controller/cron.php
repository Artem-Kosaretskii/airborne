<?php
declare(strict_types=1);

use Airborne\DuplicateProcessor;

if (function_exists('fastcgi_finish_request')){ fastcgi_finish_request(); }
require_once __DIR__ . '/../init.php';
require_once __DIR__.'/DuplicateProcessor.php';
require_once __DIR__.'./../cache/Cache.php';

ini_set('error_log',__DIR__ .'/../../logs/phpErrors.log');
ini_set('log_errors', true);
ini_set('error_reporting', E_ALL);
ini_set('memory_limit','1024M');
set_time_limit(0);

$processor = new DuplicateProcessor();

$processor->log->log('++++++++++START+++++++++++');
$cron_opts = CRON_OPTS;
try {
    $processor->process($cron_opts);
} catch (Exception $error) {
    $processor->badlog->log($error->getMessage());
}
$processor->log->log('++++++++++FINISH++++++++++');
