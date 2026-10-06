<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{Database,NotificationService,SecurityService,SupportService};

$db=Database::connection();$svc=new SupportService($db);$security=new SecurityService($db);$error='';$created=null;
$userId=!empty($_SESSION['user_id'])?(int)$_SESSION['user_id']:null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $email=(string)($_POST['email']??'');$client=SecurityService::clientIdentifier();
        $security->assertLoginAllowed('support-email',$email,5,3600);
        $security->assertLoginAllowed('support-ip',$client,20,3600);
        $created=$svc->create($userId,$_POST);
        $security->recordLoginFailure('support-email',$email,5,3600);
        $security->recordLoginFailure('support-ip',$client,20,3600);

        $notifications=new NotificationService($db);
        $ack="Thanks for contacting Fudge Donuts.\n\nTicket: {$created['ticket_number']}\nSubject: {$created['subject']}\n\nWe’ll reply as soon as possible.";
        $notifications->queue((string)$created['email'],'We received your Fudge Donuts support request',$ack,'support-ack:'.$created['id']);
        $supportEmail=trim((string)env('SUPPORT_EMAIL',''));
        if($supportEmail!=='' && filter_var($supportEmail,FILTER_VALIDATE_EMAIL)){
            $internal="New support ticket {$created['ticket_number']}\n\nCustomer: {$created['customer_name']} <{$created['email']}>\nSubject: {$created['subject']}".(!empty($created['order_number'])?"\nOrder: {$created['order_number']}":'');
            $notifications->queue($supportEmail,'New Fudge Donuts support ticket '.$created['ticket_number'],$internal,'support-internal:'.$created['id']);
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Contact Support · Fudge Donuts</title><link rel="stylesheet" href="/assets/app.css"></head><body>
<header class="nav"><a class="brand" href="/">Fudge Donuts</a><nav><a href="/faq.php">FAQ</a><a href="/account.php">Account</a><a href="/cart.php">Cart</a></nav></header>
<main class="section narrow"><p class="eyebrow">Customer support</p><h1>How can we help?</h1>
<?php if($created):?><div class="notice"><strong>We received your message.</strong><br>Your support ticket is <?=htmlspecialchars($created['ticket_number'])?>. A confirmation has been sent to <?=htmlspecialchars($created['email'])?>.</div>
<?php else:?>
<p>Questions about an order, pickup, shipping, ingredients, gifting, or your account? Send us a message here.</p>
<?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?>
<form method="post" class="admin-form support-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>">
<label>Name<input name="customer_name" required maxlength="190" value="<?=htmlspecialchars((string)($_POST['customer_name']??''))?>"></label>
<label>Email<input type="email" name="email" required maxlength="190" value="<?=htmlspecialchars((string)($_POST['email']??''))?>"></label>
<label>Order number <small>optional</small><input name="order_number" maxlength="32" placeholder="FD-..." value="<?=htmlspecialchars((string)($_POST['order_number']??''))?>"></label>
<label>Subject<input name="subject" required minlength="3" maxlength="190" value="<?=htmlspecialchars((string)($_POST['subject']??''))?>"></label>
<label>Message<textarea name="message" required minlength="10" maxlength="5000" rows="8"><?=htmlspecialchars((string)($_POST['message']??''))?></textarea></label>
<button class="button">Send support request</button></form><?php endif;?>
</main></body></html>