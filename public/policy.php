<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{ContentService,Database};

$type=(string)($_GET['type']??'terms');
$allowed=[
 'terms'=>['terms_title','terms_body'],
 'privacy'=>['privacy_title','privacy_body'],
 'refunds'=>['refund_title','refund_body'],
 'shipping'=>['shipping_policy_title','shipping_policy_body'],
];
if(!isset($allowed[$type])){http_response_code(404);exit('Policy not found');}
$c=new ContentService(Database::connection());[$titleKey,$bodyKey]=$allowed[$type];
$title=$c->get($titleKey,ucwords($type));$body=$c->get($bodyKey,'Policy content is being prepared.');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($title)?> · Fudge Donuts</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body><header class="nav"><a class="brand" href="/">Fudge Donuts</a><nav><a href="/">Shop</a><a href="/faq.php">FAQ</a></nav></header><main class="section narrow policy-page"><p class="eyebrow">Store policy</p><h1><?=htmlspecialchars($title)?></h1><div class="policy-copy"><?=nl2br(htmlspecialchars($body))?></div><p><a href="/">Back to store</a></p></main></body></html>
