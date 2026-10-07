<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuthService,Database,InstallerService,SecurityService};

$root=dirname(__DIR__);
$installer=new InstallerService($root);
$requirements=$installer->requirements();
$error='';
$database=null;
$ready=false;

try{
    $database=$installer->prepareDatabase();
    $ready=true;
    $auth=new AdminAuthService(Database::connection());
    if($auth->isInstalled()){
        header('Location: /admin.php');
        exit;
    }

    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf($_POST['_csrf']??null);
        $id=$auth->createFirstAdmin($_POST);
        session_regenerate_id(true);
        $_SESSION['admin']=true;
        $_SESSION['admin_id']=$id;
        $_SESSION['admin_role']='super_admin';
        SecurityService::initializeAuthSession($_SESSION,'admin');
        rotate_csrf_token();
        header('Location: /admin.php');
        exit;
    }
}catch(Throwable $e){
    $error=$e->getMessage();
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install · Fudge Donuts</title>
<link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>">
</head>
<body>
<main class="section narrow setup-card">
<p class="eyebrow">Fudge Donuts</p>
<h1>Install the store</h1>
<p>This one-page installer creates the SQLite database, runs all database migrations, and creates the first administrator.</p>

<h2>Database setup</h2>
<div class="admin-form">
<?php foreach($requirements as $check): ?>
<div class="notice <?=$check['ok']?'success':'error'?>">
<strong><?=$check['ok']?'Ready':'Needs attention'?> · <?=htmlspecialchars($check['name'])?></strong><br>
<?=htmlspecialchars($check['message'])?>
</div>
<?php endforeach; ?>
<?php if($database): ?>
<div class="notice success">
<strong>Database ready</strong><br>
<?=htmlspecialchars((string)$database['total'])?> migrations applied · <?=htmlspecialchars((string)$database['pending'])?> pending · <?=htmlspecialchars((string)$database['drift'])?> drift
</div>
<?php endif; ?>
<?php if($error): ?><div class="notice error"><strong>Installer stopped</strong><br><?=htmlspecialchars($error)?></div><?php endif; ?>
</div>

<?php if($ready): ?>
<h2>Create the first administrator</h2>
<form method="post" class="admin-form">
<input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>">
<div class="two">
<label>First name<input name="first_name" required autocomplete="given-name"></label>
<label>Last name<input name="last_name" required autocomplete="family-name"></label>
</div>
<label>Email<input type="email" name="email" required autocomplete="email"></label>
<label>Password<input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
<label>Confirm password<input type="password" name="password_confirmation" required minlength="12" autocomplete="new-password"></label>
<small>Use at least 12 characters with uppercase, lowercase, and a number.</small>
<button class="button" type="submit">Create administrator & finish</button>
</form>
<?php else: ?>
<p>Correct the database requirement above, then reload this page.</p>
<?php endif; ?>
</main>
</body>
</html>
