<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{Database,ReorderService};
if(empty($_SESSION['user_id'])){header('Location: /account.php',true,303);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: /account.php',true,303);exit;}
verify_csrf($_POST['_csrf']??null);

$orderId=(int)($_POST['order_id']??0);
try{
    $result=(new ReorderService(Database::connection()))->reorder((int)$_SESSION['user_id'],$orderId,$_SESSION);
    $_SESSION['cart_notice']='Added '.$result['boxes_added'].' box'.($result['boxes_added']===1?'':'es').' from '.$result['order_number'].' using current prices and availability.';
    header('Location: /cart.php',true,303);exit;
}catch(Throwable $e){
    $_SESSION['account_flash']=$e->getMessage();
    header('Location: /account-order.php?id='.$orderId,true,303);exit;
}
