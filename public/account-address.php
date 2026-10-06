<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{AuthService,Database};
if(empty($_SESSION['user_id'])){header('Location: /account.php');exit;}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: /account.php');exit;}
verify_csrf($_POST['_csrf']??null);
$auth=new AuthService(Database::connection());$uid=(int)$_SESSION['user_id'];
try{
 $action=(string)($_POST['action']??'');
 $id=(int)($_POST['address_id']??0);
 if($action==='update'){$auth->updateAddress($uid,$id,$_POST);$_SESSION['account_flash']='Address updated.';}
 elseif($action==='delete'){$auth->deleteAddress($uid,$id);$_SESSION['account_flash']='Address deleted.';}
 elseif($action==='default'){$auth->setDefaultAddress($uid,$id);$_SESSION['account_flash']='Default address updated.';}
 else throw new InvalidArgumentException('Unsupported address action.');
}catch(Throwable $e){$_SESSION['account_flash']=$e->getMessage();}
header('Location: /account.php#addresses');exit;
