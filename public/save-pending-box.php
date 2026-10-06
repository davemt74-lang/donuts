<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{CustomerAccountService,Database};
if(empty($_SESSION['user_id'])){header('Location: /account.php');exit;}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: /builder-review.php');exit;}
verify_csrf($_POST['_csrf']??null);
$box=$_SESSION['pending_box']??null;
try{
 if(!is_array($box))throw new InvalidArgumentException('There is no box to save.');
 (new CustomerAccountService(Database::connection()))->saveBox((int)$_SESSION['user_id'],(string)($_POST['name']??''),$box);
 $_SESSION['account_flash']='Your custom box was saved.';
 header('Location: /account.php#saved-boxes');exit;
}catch(Throwable $e){$_SESSION['builder_flash']=$e->getMessage();header('Location: /builder-review.php');exit;}
