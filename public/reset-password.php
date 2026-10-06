<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{Database,PasswordResetService};
$token=(string)($_GET['token']??$_POST['token']??'');$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf($_POST['_csrf']??null);
 try{
   $id=(new PasswordResetService(Database::connection()))->consume($token,(string)($_POST['password']??''),(string)($_POST['password_confirmation']??''));
   session_regenerate_id(true);$_SESSION['user_id']=$id;header('Location: /account.php?password=reset');exit;
 }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Choose new password · Fudge Donuts</title><link rel="stylesheet" href="/assets/app.css"></head><body><header class="nav"><a class="brand" href="/">Fudge Donuts</a></header><main class="section narrow"><p class="eyebrow">Account security</p><h1>Choose a new password</h1><?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="token" value="<?=htmlspecialchars($token)?>"><input type="password" name="password" minlength="10" required autocomplete="new-password" placeholder="New password"><input type="password" name="password_confirmation" minlength="10" required autocomplete="new-password" placeholder="Confirm new password"><button class="button">Update password</button></form></main></body></html>
