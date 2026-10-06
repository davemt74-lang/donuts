<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{AuthService,CustomerAccountService,Database,SecurityService};

$db=Database::connection();
$auth=new AuthService($db);
$security=new SecurityService($db);
$action=(string)($_GET['action']??'');
$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf($_POST['_csrf']??null);
 $mode=(string)($_POST['mode']??'');
 try{
  if($mode==='register'){
   $id=$auth->register((string)($_POST['email']??''),(string)($_POST['password']??''),(string)($_POST['first_name']??''),(string)($_POST['last_name']??''));
   session_regenerate_id(true);$_SESSION['user_id']=$id;header('Location: /account.php');exit;
  }
  if($mode==='login'){
   $email=(string)($_POST['email']??'');
   $security->assertLoginAllowed('account',$email);
   $user=$auth->login($email,(string)($_POST['password']??''));
   if(!$user){$security->recordLoginFailure('account',$email);throw new InvalidArgumentException('Email or password is incorrect.');}
   $security->clearLoginFailures('account',$email);
   session_regenerate_id(true);$_SESSION['user_id']=(int)$user['id'];header('Location: /account.php');exit;
  }
  if($mode==='logout'){unset($_SESSION['user_id']);session_regenerate_id(true);header('Location: /');exit;}
  if($mode==='address'){
   if(empty($_SESSION['user_id'])) throw new InvalidArgumentException('Sign in first.');
   $auth->saveAddress((int)$_SESSION['user_id'],$_POST);header('Location: /account.php');exit;
  }
 }catch(Throwable $e){$error=$e->getMessage();}
}
$user=!empty($_SESSION['user_id'])?$auth->user((int)$_SESSION['user_id']):null;
$addresses=$user?$auth->addresses((int)$user['id']):[];
$accountService=$user?new CustomerAccountService($db):null;
$orders=$accountService?$accountService->orders((int)$user['id']):[];
$savedBoxes=$accountService?$accountService->savedBoxes((int)$user['id']):[];
$accountFlash=(string)($_SESSION['account_flash']??'');unset($_SESSION['account_flash']);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Account · Fudge Donuts</title><link rel="stylesheet" href="/assets/app.css"></head><body>
<header class="nav"><a class="brand" href="/">Fudge Donuts</a><nav><a href="/cart.php">Cart</a></nav></header><main class="section account-page">
<?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?>
<?php if(!$user):?>
<p class="eyebrow">Your account</p><h1><?= $action==='register'?'Create account':'Welcome back' ?></h1>
<form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="mode" value="<?=$action==='register'?'register':'login'?>">
<?php if($action==='register'):?><input name="first_name" placeholder="First name"><input name="last_name" placeholder="Last name"><?php endif;?>
<input type="email" name="email" required placeholder="Email"><input type="password" name="password" required minlength="10" placeholder="Password"><button class="button"><?=$action==='register'?'Create account':'Sign in'?></button></form>
<p><?=$action==='register'?'Already have an account? <a href="/account.php">Sign in</a>':'New here? <a href="/account.php?action=register">Create an account</a>'?></p><?php if($action!=='register'):?><p><a href="/forgot-password.php">Forgot your password?</a></p><?php endif;?>
<?php else:?>
<p class="eyebrow">Account</p><h1>Hello, <?=htmlspecialchars($user['first_name']?:'there')?></h1><p><?=htmlspecialchars($user['email'])?></p><?php if(!empty($_GET['password'])):?><div class="notice">Your password has been updated.</div><?php endif;?><?php if(!empty($_GET['box'])):?><div class="notice">Your box has been saved.</div><?php endif;?><?php if($accountFlash):?><div class="notice error"><?=htmlspecialchars($accountFlash)?></div><?php endif;?>
<form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="mode" value="logout"><button class="link">Sign out</button></form>
<div class="account-nav"><a href="#profile">Profile</a><a href="#orders">Orders</a><a href="#saved-boxes">Saved boxes</a><a href="#addresses">Addresses</a></div>
<section id="profile" class="account-section"><div class="account-section-head"><div><p class="eyebrow">Profile</p><h2>Your details</h2></div></div><form method="post" action="/account-profile.php" class="admin-form account-profile-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><div class="two"><label>First name<input name="first_name" required value="<?=htmlspecialchars((string)$user['first_name'])?>"></label><label>Last name<input name="last_name" required value="<?=htmlspecialchars((string)$user['last_name'])?>"></label></div><label>Email<input type="email" name="email" required value="<?=htmlspecialchars((string)$user['email'])?>"></label><label class="check"><input type="checkbox" name="marketing_opt_in" value="1" <?=(int)$user['marketing_opt_in']?'checked':''?>> Send me product launches, seasonal flavors and offers</label><button class="button secondary">Save profile</button></form></section>
<section id="orders" class="account-section"><div class="account-section-head"><div><p class="eyebrow">Purchase history</p><h2>Your orders</h2></div></div><?php if(!$orders):?><div class="dashboard-empty"><strong>No orders yet.</strong><span>Your completed purchases will appear here.</span></div><?php else:?><div class="account-order-list"><?php foreach($orders as $o):?><a href="/account-order.php?id=<?=(int)$o['id']?>"><span><strong><?=htmlspecialchars($o['order_number'])?></strong><small><?=htmlspecialchars($o['created_at'])?> · <?=htmlspecialchars(ucwords(str_replace('_',' ',$o['status'])))?></small></span><strong><?=money((int)$o['total_cents'])?></strong></a><?php endforeach;?></div><?php endif;?></section>
<section id="saved-boxes" class="account-section"><div class="account-section-head"><div><p class="eyebrow">Favorites</p><h2>Saved boxes</h2></div><a href="/builder.php?size=12">Build a new box</a></div><?php if(!$savedBoxes):?><div class="dashboard-empty"><strong>No saved boxes yet.</strong><span>Save a combination from an order and reorder it anytime.</span></div><?php else:?><div class="saved-box-grid"><?php foreach($savedBoxes as $b):?><article class="saved-box-card"><div><p class="eyebrow"><?=htmlspecialchars($b['box_type'])?> <?=(int)$b['pack_size']?> pack</p><h3><?=htmlspecialchars($b['name'])?></h3><small>Saved <?=htmlspecialchars($b['updated_at'])?></small></div><div class="actions"><form method="post" action="/saved-box.php"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="id" value="<?=(int)$b['id']?>"><input type="hidden" name="action" value="reorder"><button class="button secondary">Add to cart</button></form><form method="post" action="/saved-box.php"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="id" value="<?=(int)$b['id']?>"><input type="hidden" name="action" value="delete"><button class="link">Delete</button></form></div></article><?php endforeach;?></div><?php endif;?></section>
<section id="addresses" class="account-section"><p class="eyebrow">Checkout</p><h2>Saved addresses</h2><div class="address-book"><?php foreach($addresses as $a):?><article class="address-card account-address-card"><div class="address-card-head"><strong><?=htmlspecialchars($a['label'])?></strong><?php if((int)$a['is_default']):?><span class="status">Default</span><?php endif;?></div><p><?=htmlspecialchars($a['first_name'].' '.$a['last_name'])?><br><?=htmlspecialchars($a['line1'])?><?=trim((string)$a['line2'])!==''?'<br>'.htmlspecialchars($a['line2']):''?><br><?=htmlspecialchars($a['city'].', '.$a['region'].' '.$a['postal_code'])?></p><details><summary>Edit address</summary><form method="post" action="/account-address.php" class="admin-form address-edit-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="update"><input type="hidden" name="address_id" value="<?=(int)$a['id']?>"><input name="label" value="<?=htmlspecialchars($a['label'])?>" placeholder="Label"><div class="two"><input name="first_name" required value="<?=htmlspecialchars($a['first_name'])?>" placeholder="First name"><input name="last_name" required value="<?=htmlspecialchars($a['last_name'])?>" placeholder="Last name"></div><input name="line1" required value="<?=htmlspecialchars($a['line1'])?>" placeholder="Address"><input name="line2" value="<?=htmlspecialchars($a['line2'])?>" placeholder="Apartment, suite, etc."><div class="three"><input name="city" required value="<?=htmlspecialchars($a['city'])?>" placeholder="City"><input name="region" required value="<?=htmlspecialchars($a['region'])?>" placeholder="State"><input name="postal_code" required value="<?=htmlspecialchars($a['postal_code'])?>" placeholder="ZIP"></div><input name="country" value="<?=htmlspecialchars($a['country'])?>" maxlength="2"><input name="phone" value="<?=htmlspecialchars($a['phone'])?>" placeholder="Phone"><label><input type="checkbox" name="is_default" value="1" <?=(int)$a['is_default']?'checked':''?>> Make default</label><button class="button secondary">Save address</button></form></details><div class="address-actions"><?php if(!(int)$a['is_default']):?><form method="post" action="/account-address.php"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="default"><input type="hidden" name="address_id" value="<?=(int)$a['id']?>"><button class="link">Make default</button></form><?php endif;?><form method="post" action="/account-address.php" onsubmit="return confirm('Delete this address?')"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="address_id" value="<?=(int)$a['id']?>"><button class="link">Delete</button></form></div></article><?php endforeach;?></div>
<h3>Add address</h3><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="mode" value="address"><input name="label" placeholder="Label" value="Home"><input name="first_name" required placeholder="First name"><input name="last_name" required placeholder="Last name"><input name="line1" required placeholder="Address"><input name="line2" placeholder="Apartment, suite, etc."><input name="city" required placeholder="City"><input name="region" required placeholder="State"><input name="postal_code" required placeholder="ZIP code"><input name="country" value="US" maxlength="2"><input name="phone" placeholder="Phone"><label><input type="checkbox" name="is_default" value="1"> Make default</label><button class="button">Save address</button></form>
</section><?php endif;?></main></body></html>
