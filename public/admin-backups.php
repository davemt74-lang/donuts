<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuditService,BackupService,Database};
require_admin_roles(['super_admin']);

$db=Database::connection();$svc=new BackupService($db,dirname(__DIR__));$audit=new AdminAuditService($db);$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $meta=$svc->create('manual-admin');
        $svc->prune((int)env('BACKUP_RETENTION_DAYS','14'),(int)env('BACKUP_MAX_FILES','60'));
        $audit->record((int)$_SESSION['admin_id'],'database_backup_created','backup',$meta['file'],'Manual database backup created.',[],['file'=>$meta['file'],'bytes'=>$meta['bytes'],'sha256'=>$meta['sha256']]);
        $notice='Backup created and verified.';
    }catch(Throwable $e){$error=$e->getMessage();}
}
$rows=$svc->list();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Backups · Fudge Donuts Admin</title><link rel="stylesheet" href="/assets/app.css"></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-orders.php">Orders</a><a href="/admin-inventory.php">Inventory</a><a href="/admin-reports.php">Reports</a><a href="/admin-audit.php">Audit</a><a class="active" href="/admin-backups.php">Backups</a></nav></header>
<main class="admin-shell"><div class="admin-page-head"><div><p class="eyebrow">Recovery operations</p><h1>Database Backups</h1><p class="admin-welcome">Verified SQLite snapshots stored outside the public web root.</p></div><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><button class="button">Create verified backup</button></form></div>
<?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice"><?=htmlspecialchars($notice)?></div><?php endif;?>
<div class="allergen-callout"><strong>Restore safety</strong><p>Database restores are intentionally CLI-only. A restore places the storefront in maintenance mode and creates a pre-restore backup automatically.</p></div>
<section class="dashboard-panel"><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Backup</th><th>Created</th><th>Size</th><th>SHA-256</th><th>Integrity</th></tr></thead><tbody><?php if(!$rows):?><tr><td colspan="5" class="empty-cell">No backups yet.</td></tr><?php endif;?><?php foreach($rows as $row):$check=$svc->verify($row['path']);?><tr><td><strong><?=htmlspecialchars($row['file'])?></strong></td><td><?=htmlspecialchars($row['modified_at'])?></td><td><?=number_format($row['bytes']/1024,1)?> KB</td><td><code><?=htmlspecialchars(substr($row['sha256'],0,16))?>…</code></td><td><span class="status <?=$check['ok']?'status-paid':'status-payment_failed'?>"><?=$check['ok']?'Verified':'Failed'?></span></td></tr><?php endforeach;?></tbody></table></div></section>
</main></body></html>