<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{AuthService,Database};
if(empty($_SESSION['user_id'])){header('Location: /account.php');exit;}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: /account.php');exit;}
verify_csrf($_POST['_csrf']??null);
try{
 (new AuthService(Database::connection()))->updateProfile((int)$_SESSION['user_id'],$_POST);
 $_SESSION['account_flash']='Profile updated.';
}catch(Throwable $e){$_SESSION['account_flash']=$e->getMessage();}
header('Location: /account.php#profile');exit;
