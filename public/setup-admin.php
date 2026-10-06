<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuthService,Database};

$db=Database::connection();
$auth=new AdminAuthService($db);
if($auth->isInstalled()){header('Location: /admin.php');exit;}

$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $id=$auth->createFirstAdmin($_POST);
        session_regenerate_id(true);
        $_SESSION['admin']=true;
        $_SESSION['admin_id']=$id;
        $_SESSION['admin_role']='super_admin';
        header('Location: /admin.php');exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Create Store Administrator · Fudge Donuts</title><link rel="stylesheet" href="/assets/app.css"></head><body>
<main class="section narrow setup-card"><p class="eyebrow">First-time setup</p><h1>Create your administrator</h1><p>This form is available only until the first administrator is created.</p>
<?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?>
<form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>">
<div class="two"><label>First name<input name="first_name" required autocomplete="given-name"></label><label>Last name<input name="last_name" required autocomplete="family-name"></label></div>
<label>Email<input type="email" name="email" required autocomplete="email"></label>
<label>Password<input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
<label>Confirm password<input type="password" name="password_confirmation" required minlength="12" autocomplete="new-password"></label>
<small>Use at least 12 characters with uppercase, lowercase, and a number.</small>
<button class="button" type="submit">Create Super Admin</button></form>
</main></body></html>
