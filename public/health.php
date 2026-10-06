<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\PreflightService;

header('Content-Type: application/json; charset=UTF-8');
$healthy=(new PreflightService())->healthy();
http_response_code($healthy?200:503);
echo json_encode(['status'=>$healthy?'ok':'unhealthy'],JSON_THROW_ON_ERROR);
