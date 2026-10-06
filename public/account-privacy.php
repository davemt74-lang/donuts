<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{CustomerPrivacyService,Database};

if(empty($_SESSION['user_id'])){header('Location: /account.php');exit;}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: /account.php#privacy');exit;}
verify_csrf($_POST['_csrf']??null);

$db=Database::connection();$svc=new CustomerPrivacyService($db);$uid=(int)$_SESSION['user_id'];
$action=(string)($_POST['action']??'');
$password=(string)($_POST['password']??'');

try{
    if(!$svc->verifyPassword($uid,$password)) throw new InvalidArgumentException('Password is incorrect.');

    if($action==='export'){
        $data=$svc->exportData($uid);
        $filename='fudge-donuts-account-data-'.gmdate('Ymd-His').'.json';
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        exit;
    }

    if($action==='close'){
        if((string)($_POST['confirm_text']??'')!=='CLOSE') throw new InvalidArgumentException('Type CLOSE to confirm account closure.');
        $svc->closeAccount($uid,$password);
        $_SESSION=[];
        if(ini_get('session.use_cookies')){
            $params=session_get_cookie_params();
            setcookie(session_name(),'',time()-42000,$params['path'],$params['domain'],$params['secure'],$params['httponly']);
        }
        session_destroy();
        header('Location: /?account=closed');exit;
    }

    throw new InvalidArgumentException('Unsupported privacy action.');
}catch(Throwable $e){
    $_SESSION['account_flash']=$e->getMessage();
    header('Location: /account.php#privacy');exit;
}
