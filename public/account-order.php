<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{CustomerAccountService,Database,RefundService,ReorderService};
if(empty($_SESSION['user_id'])){header('Location: /account.php');exit;}
$db=Database::connection();$accounts=new CustomerAccountService($db);$order=$accounts->order((int)$_SESSION['user_id'],(int)($_GET['id']??$_POST['id']??0));
if(!$order){http_response_code(404);exit('Order not found');}
$error='';$notice='';$refundService=new RefundService($db);
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf($_POST['_csrf']??null);
 try{
   if(($_POST['action']??'')==='reorder'){
     $result=(new ReorderService($db))->reorder((int)$_SESSION['user_id'],(int)$order['id'],$_SESSION);
     $_SESSION['flash']='Added '.$result['boxes_added'].' box'.($result['boxes_added']===1?'':'es').' from '.$result['order_number'].' using current prices and availability.';
     header('Location: /cart.php',true,303);exit;
   }
   if(($_POST['action']??'')==='cancel'){
     $refundService->requestCancellation((int)$order['id'],(int)$_SESSION['user_id'],(string)($_POST['reason']??''));
     $notice='Your cancellation request has been sent.';
   }
   if(($_POST['action']??'')==='save'){
     $index=(int)($_POST['item_index']??-1);if(!isset($order['items'][$index]))throw new InvalidArgumentException('Order item not found.');
     $cfg=json_decode((string)$order['items'][$index]['configuration_json'],true,512,JSON_THROW_ON_ERROR);
     $accounts->saveBox((int)$_SESSION['user_id'],(string)($_POST['name']??''),$cfg);
     header('Location: /account.php?box=saved');exit;
   }
 }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($order['order_number'])?> · Fudge Donuts</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body><header class="nav"><a class="brand" href="/">Fudge Donuts</a><nav><a href="/account.php">Account</a><a href="/cart.php">Cart</a></nav></header><main class="section narrow"><p class="eyebrow">Order history</p><h1><?=htmlspecialchars($order['order_number'])?></h1><?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice"><?=htmlspecialchars($notice)?></div><?php endif;?><div class="address-card"><strong><?=htmlspecialchars(ucwords(str_replace('_',' ',$order['status'])))?></strong><p><?=htmlspecialchars($order['created_at'])?><br><?=htmlspecialchars($order['fulfillment_name'])?><br><?=htmlspecialchars($order['city'].', '.$order['region'].' '.$order['postal_code'])?></p><?php $fd=$order['fulfillment_details']??null;if($fd):?><?php if(!empty($fd['tracking_number'])):?><p><strong><?=htmlspecialchars((string)$fd['carrier'])?></strong> · <?=htmlspecialchars((string)$fd['tracking_number'])?><?php if(!empty($fd['tracking_url'])):?><br><a href="<?=htmlspecialchars((string)$fd['tracking_url'])?>" target="_blank" rel="noopener">Track shipment</a><?php endif;?></p><?php endif;?><?php if(!empty($fd['pickup_instructions'])):?><p><strong>Pickup instructions</strong><br><?=nl2br(htmlspecialchars((string)$fd['pickup_instructions']))?></p><?php endif;?><?php if(!empty($fd['pickup_ready_at'])):?><p>Pickup ready: <?=htmlspecialchars((string)$fd['pickup_ready_at'])?></p><?php endif;?><?php endif;?></div><div class="review-list"><?php foreach($order['items'] as $idx=>$item):$cfg=json_decode((string)$item['configuration_json'],true);?><div class="account-order-item"><div><strong><?=htmlspecialchars(($cfg['type']??'')==='preset'?($cfg['name']??'Preset box'):'Custom '.(int)$item['pack_size'].' Pack')?> × <?=(int)$item['quantity']?></strong><p><?php foreach($cfg['items']??[] as $flavor):?><?=htmlspecialchars($flavor['name'])?> × <?=(int)$flavor['quantity']?> · <?php endforeach;?></p></div><strong><?=money((int)$item['line_total_cents'])?></strong><form method="post" class="save-box-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="id" value="<?=(int)$order['id']?>"><input type="hidden" name="action" value="save"><input type="hidden" name="item_index" value="<?=$idx?>"><input name="name" required placeholder="Save this box as…"><button class="link">Save box</button></form></div><?php endforeach;?></div><div class="review-total"><span>Total</span><strong><?=money((int)$order['total_cents'])?></strong></div><div class="actions"><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="id" value="<?=(int)$order['id']?>"><input type="hidden" name="action" value="reorder"><button class="button">Buy these boxes again</button></form><span class="muted">Rebuilt with today’s prices and current flavor availability.</span><?php $cancel=$refundService->pendingCancellation((int)$order['id']);if($cancel):?><span class="muted">Cancellation requested</span><?php elseif(in_array($order['status'],['pending_payment','paid','preparing'],true)):?><form method="post" class="cancel-request-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="id" value="<?=(int)$order['id']?>"><input type="hidden" name="action" value="cancel"><input name="reason" maxlength="500" placeholder="Reason for cancellation"><button class="link">Request cancellation</button></form><?php endif;?></div></main></body></html>
