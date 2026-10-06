<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{Database,MarketingConsentService};

$token=trim((string)($_GET['token']??''));
$success=false;
if($token!==''){
    try{
        $svc=new MarketingConsentService(
            Database::connection(),
            (string)env('APP_KEY',''),
            (string)env('APP_URL','http://127.0.0.1:8080')
        );
        $email=$svc->emailFromToken($token);
        if($email!==null){
            $svc->unsubscribe($email,'unsubscribe_link');
            $success=true;
        }
    }catch(Throwable){}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Unsubscribe · Fudge Donuts</title><link rel="stylesheet" href="/assets/app.css"></head><body><header class="nav"><a class="brand" href="/">Fudge Donuts</a></header><main class="section narrow"><p class="eyebrow">Email preferences</p><?php if($success):?><h1>You’re unsubscribed.</h1><p>You won’t receive Fudge Donuts marketing emails at this address unless you explicitly subscribe again.</p><?php else:?><h1>This unsubscribe link isn’t valid.</h1><p>Use the unsubscribe link from your most recent marketing email, or update your preferences from your account.</p><?php endif;?><a class="button" href="/">Back to store</a></main></body></html>