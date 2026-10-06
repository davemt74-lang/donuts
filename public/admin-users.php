<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuthService,Database};

require_admin_roles(['super_admin']);
$db=Database::connection();$auth=new AdminAuthService($db);$error='';$notice='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $action=(string)($_POST['action']??'create');
        if($action==='create'){
            $auth->createAdmin($_POST,(string)($_POST['role']??'admin'),(int)$_SESSION['admin_id']);
            $notice='Administrator created.';
        }elseif($action==='toggle'){
            $auth->setActive((int)$_POST['admin_id'],(bool)(int)$_POST['active'],(int)$_SESSION['admin_id']);
            $notice='Administrator updated.';
        }elseif($action==='password'){
            $auth->changePassword((int)$_SESSION['admin_id'],(string)($_POST['current_password']??''),(string)($_POST['new_password']??''));
            $notice='Password changed.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
$admins=$auth->all();
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/app.css"><title>Administrators · Fudge Donuts</title></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-flavors.php">Flavors</a><a href="/admin-packs.php">Packs</a><a href="/admin-orders.php">Orders</a><a href="/admin-inventory.php">Inventory</a><a href="/admin-shipping.php">Shipping</a><a href="/admin-promotions.php">Promotions</a><a href="/admin-content.php">Content</a><a href="/admin-reports.php">Reports</a><a href="/admin-notifications.php">Email</a><a href="/admin-marketing.php">Marketing</a><a href="/admin-audit.php">Audit</a><a href="/admin-operations.php">Operations</a><a class="active" href="/admin-users.php">Administrators</a></nav></header>
<main class="admin-shell"><p class="eyebrow">Access control</p><h1>Administrators</h1>
<?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice"><?=htmlspecialchars($notice)?></div><?php endif;?>
<table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr></thead><tbody>
<?php foreach($admins as $a):?><tr><td><?=htmlspecialchars($a['first_name'].' '.$a['last_name'])?></td><td><?=htmlspecialchars($a['email'])?></td><td><?=htmlspecialchars(str_replace('_',' ',$a['role']))?></td><td><?=(int)$a['active']?'Active':'Disabled'?></td><td><?=htmlspecialchars((string)($a['last_login_at']??'Never'))?></td><td><?php if((int)$a['id']!==(int)$_SESSION['admin_id']):?><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="admin_id" value="<?=(int)$a['id']?>"><input type="hidden" name="active" value="<?=(int)$a['active']?0:1?>"><button class="link"><?=(int)$a['active']?'Disable':'Enable'?></button></form><?php endif;?></td></tr><?php endforeach;?>
</tbody></table>
<div class="report-grid"><section><h2>Create administrator</h2><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="create"><input name="first_name" required placeholder="First name"><input name="last_name" required placeholder="Last name"><input type="email" name="email" required placeholder="Email"><select name="role"><option value="admin">Admin</option><option value="fulfillment">Fulfillment</option><option value="super_admin">Super Admin</option></select><input type="password" name="password" required minlength="12" placeholder="Password"><input type="password" name="password_confirmation" required minlength="12" placeholder="Confirm password"><button class="button">Create administrator</button></form></section>
<section><h2>Change my password</h2><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="password"><input type="password" name="current_password" required placeholder="Current password"><input type="password" name="new_password" required minlength="12" placeholder="New password"><button class="button secondary">Change password</button></form></section></div>
</main></body></html>
