<?php
declare(strict_types=1);

namespace Airborne;

require_once __DIR__ . '/../init.php';
require_once __DIR__.'/XLSLoader.php';
require_once __DIR__.'/XLSProcessor.php';
require_once __DIR__.'/SimpleXLSXEx.php';
require_once __DIR__.'/../duplicates_controller/DuplicateProcessor.php';
require_once __DIR__.'/../cache/Cache.php';

ini_set('error_log',__DIR__ .'/../../logs/phpErrors.log');
ini_set('log_errors', true);
ini_set('error_reporting', E_ALL);
ini_set('memory_limit',MEMORY_LIMIT);
set_time_limit(0);

$processor = new XLSLoader();
$input = file_get_contents('php://input') ?? '';
$processor->process($input);
