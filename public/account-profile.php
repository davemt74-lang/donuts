<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{AuthService,Database,MarketingConsentService};

if(empty($_SESSION['user_id'])){header('Location: /account.php');exit;}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: /account.php');exit;}
verify_csrf($_POST['_csrf']??null);

$db=Database::connection();$auth=new AuthService($db);$uid=(int)$_SESSION['user_id'];
try{
    $before=$auth->user($uid);
    if(!$before) throw new RuntimeException('Account not found.');

    $auth->updateProfile($uid,$_POST);
    $after=$auth->user($uid);
    if(!$after) throw new RuntimeException('Account not found after update.');

    $marketing=new MarketingConsentService(
        $db,
        (string)env('APP_KEY',''),
        (string)env('APP_URL','http://127.0.0.1:8080')
    );

    $beforeEmail=strtolower((string)$before['email']);
    $afterEmail=strtolower((string)$after['email']);
    if($beforeEmail!==$afterEmail){
        $marketing->unsubscribe($beforeEmail,'account_email_changed');
    }

    if(!empty($_POST['marketing_opt_in'])){
        $marketing->subscribe($afterEmail,'account_profile',true);
    }else{
        $marketing->unsubscribe($afterEmail,'account_profile');
    }

    $_SESSION['account_flash']='Profile updated.';
}catch(Throwable $e){
    $_SESSION['account_flash']=$e->getMessage();
}
header('Location: /account.php#profile');exit;
