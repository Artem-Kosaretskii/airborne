<?php
declare(strict_types=1);

namespace Airborne;

require_once __DIR__.'/../init.php';
require_once __DIR__.'/ScaleProcessor.php';

ini_set('error_log',__DIR__ .'/../../logs/phpErrors.log');
ini_set('log_errors', true);
ini_set('error_reporting', E_ALL);
set_time_limit(0);

if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
$processor = new ScaleProcessor();
$processor->log->log('++++++++++START+++++++++++');
$processed = $processor->process();
$processor->log->log('New records: '.count($processed['new_records'] ?? []).', Created notes: '.count($processed['new_notes_are_created'] ?? []).', Moved leads: '.count($processed['leads_are_moved'] ?? []));
$processor->log->log('++++++++++FINISH++++++++++');
