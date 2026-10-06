<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{Database,MarketingConsentService};

if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: /');exit;}
verify_csrf($_POST['_csrf']??null);
try{
    (new MarketingConsentService(
        Database::connection(),
        (string)env('APP_KEY',''),
        (string)env('APP_URL','http://127.0.0.1:8080')
    ))->subscribe(
        (string)($_POST['email']??''),
        'homepage_newsletter',
        !empty($_POST['marketing_consent'])
    );
    $_SESSION['flash']='Thanks for joining the Fudge Donuts list.';
}catch(Throwable $e){
    $_SESSION['flash']=$e->getMessage();
}
header('Location: /#newsletter');exit;
