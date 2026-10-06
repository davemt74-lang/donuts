<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{Database,NotificationService,PasswordResetService,SecurityService};

$db=Database::connection();$error='';$sent=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf($_POST['_csrf']??null);
 $email=(string)($_POST['email']??'');
 try{
   $security=new SecurityService($db);$security->assertLoginAllowed('password-reset',$email,5,3600);
   $reset=(new PasswordResetService($db))->create($email);
   if($reset){
      $base=rtrim((string)env('APP_URL','http://127.0.0.1:8080'),'/');
      $url=$base.'/reset-password.php?token='.urlencode($reset['token']);
      (new NotificationService($db))->queuePasswordReset($reset['email'],$reset['first_name'],$url,$reset['expires_at']);
   }
   $sent=true;
 }catch(Throwable $e){$error='Unable to process that request right now.';}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reset password · Fudge Donuts</title><link rel="stylesheet" href="/assets/app.css"></head><body><header class="nav"><a class="brand" href="/">Fudge Donuts</a></header><main class="section narrow"><p class="eyebrow">Account help</p><h1>Reset your password</h1><?php if($sent):?><div class="notice">If an account exists for that email, a password reset link has been sent.</div><?php else:?><p>Enter the email address on your account.</p><?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="email" name="email" required autocomplete="email" placeholder="Email address"><button class="button">Send reset link</button></form><?php endif;?><p><a href="/account.php">Back to sign in</a></p></main></body></html>
