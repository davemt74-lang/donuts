<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{AuthService,Database};

$auth=new AuthService(Database::connection());
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
   $user=$auth->login((string)($_POST['email']??''),(string)($_POST['password']??''));
   if(!$user) throw new InvalidArgumentException('Email or password is incorrect.');
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
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Account · Fudge Donuts</title><link rel="stylesheet" href="/assets/app.css"></head><body>
<header class="nav"><a class="brand" href="/">Fudge Donuts</a><nav><a href="/cart.php">Cart</a></nav></header><main class="section narrow">
<?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?>
<?php if(!$user):?>
<p class="eyebrow">Your account</p><h1><?= $action==='register'?'Create account':'Welcome back' ?></h1>
<form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="mode" value="<?=$action==='register'?'register':'login'?>">
<?php if($action==='register'):?><input name="first_name" placeholder="First name"><input name="last_name" placeholder="Last name"><?php endif;?>
<input type="email" name="email" required placeholder="Email"><input type="password" name="password" required minlength="10" placeholder="Password"><button class="button"><?=$action==='register'?'Create account':'Sign in'?></button></form>
<p><?=$action==='register'?'Already have an account? <a href="/account.php">Sign in</a>':'New here? <a href="/account.php?action=register">Create an account</a>'?></p>
<?php else:?>
<p class="eyebrow">Account</p><h1>Hello, <?=htmlspecialchars($user['first_name']?:'there')?></h1><p><?=htmlspecialchars($user['email'])?></p>
<form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="mode" value="logout"><button class="link">Sign out</button></form>
<h2>Saved addresses</h2><?php foreach($addresses as $a):?><article class="address-card"><strong><?=htmlspecialchars($a['label'])?></strong><p><?=htmlspecialchars($a['first_name'].' '.$a['last_name'])?><br><?=htmlspecialchars($a['line1'])?><br><?=htmlspecialchars($a['city'].', '.$a['region'].' '.$a['postal_code'])?></p></article><?php endforeach;?>
<h3>Add address</h3><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="mode" value="address"><input name="label" placeholder="Label" value="Home"><input name="first_name" required placeholder="First name"><input name="last_name" required placeholder="Last name"><input name="line1" required placeholder="Address"><input name="line2" placeholder="Apartment, suite, etc."><input name="city" required placeholder="City"><input name="region" required placeholder="State"><input name="postal_code" required placeholder="ZIP code"><input name="country" value="US" maxlength="2"><input name="phone" placeholder="Phone"><label><input type="checkbox" name="is_default" value="1"> Make default</label><button class="button">Save address</button></form>
<?php endif;?></main></body></html>
