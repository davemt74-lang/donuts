<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AnalyticsService,Database};

if((env('ANALYTICS_ENABLED','0')??'0')!=='1'){
    http_response_code(204);exit;
}
if($_SERVER['REQUEST_METHOD']!=='POST'){
    http_response_code(405);header('Allow: POST');exit;
}
$raw=file_get_contents('php://input');
if($raw===false || strlen($raw)>4096){http_response_code(413);exit;}
$data=json_decode($raw,true);
if(!is_array($data)){http_response_code(400);exit;}

try{
    (new AnalyticsService(Database::connection()))->record(
        (string)($data['visitor_id']??''),
        (string)($data['event']??''),
        (string)($data['path']??'/'),
        [
            'referrer_host'=>(string)($data['referrer_host']??''),
            'utm_source'=>(string)($data['utm_source']??''),
            'utm_medium'=>(string)($data['utm_medium']??''),
            'utm_campaign'=>(string)($data['utm_campaign']??''),
            'utm_content'=>(string)($data['utm_content']??''),
            'utm_term'=>(string)($data['utm_term']??''),
        ]
    );
    http_response_code(204);
}catch(Throwable){
    http_response_code(204);
}
