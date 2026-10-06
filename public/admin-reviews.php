<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuditService,Database,ReviewService};
require_admin_roles(['super_admin','admin']);

$db=Database::connection();$svc=new ReviewService($db);$audit=new AdminAuditService($db);$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $id=(int)($_POST['id']??0);$status=(string)($_POST['status']??'');
        $svc->moderate($id,$status,(int)$_SESSION['admin_id']);
        $audit->record((int)$_SESSION['admin_id'],'review_moderated','product_review',$id,'Verified customer review '.$status.'.',[],['status'=>$status]);
        $notice='Review '.$status.'.';
    }catch(Throwable $e){$error=$e->getMessage();}
}
$status=trim((string)($_GET['status']??'pending'));
try{$rows=$svc->queue($status?:null);}catch(Throwable $e){$error=$e->getMessage();$status='pending';$rows=$svc->queue('pending');}
$stats=$svc->stats();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reviews · Fudge Donuts Admin</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-orders.php">Orders</a><a class="active" href="/admin-reviews.php">Reviews</a><a href="/admin-support.php">Support</a><a href="/admin-reports.php">Reports</a><a href="/admin-audit.php">Audit</a></nav></header>
<main class="admin-shell"><div class="admin-page-head"><div><p class="eyebrow">Customer voice</p><h1>Verified Reviews</h1><p class="admin-welcome">Moderate reviews from confirmed purchasers only.</p></div></div>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice" role="status"><?=htmlspecialchars($notice)?></div><?php endif;?>
<section class="dashboard-kpis"><article class="kpi-card <?=((int)$stats['pending']>0?'kpi-alert':'')?>"><span>Pending</span><strong><?=(int)$stats['pending']?></strong><small>Needs moderation</small></article><article class="kpi-card"><span>Approved</span><strong><?=(int)$stats['approved']?></strong><small>Visible publicly</small></article><article class="kpi-card"><span>Rejected</span><strong><?=(int)$stats['rejected']?></strong><small>Hidden</small></article></section>
<div class="order-filters"><a class="<?=!$status?'active':''?>" href="/admin-reviews.php?status=">All</a><?php foreach(['pending','approved','rejected'] as $s):?><a class="<?=$status===$s?'active':''?>" href="/admin-reviews.php?status=<?=$s?>"><?=htmlspecialchars(ucfirst($s))?></a><?php endforeach;?></div>
<section class="dashboard-panel"><div class="review-admin-list"><?php if(!$rows):?><div class="dashboard-empty"><strong>No reviews in this view.</strong></div><?php endif;?><?php foreach($rows as $r):?><article class="review-admin-card"><div class="panel-head"><div><p class="eyebrow"><?=htmlspecialchars($r['flavor_name'])?> · <?=str_repeat('★',(int)$r['rating'])?><?=str_repeat('☆',5-(int)$r['rating'])?></p><h2><?=htmlspecialchars($r['title']?:'Review')?></h2><small>Verified purchase · <?=htmlspecialchars(trim($r['first_name'].' '.$r['last_name']))?> · <?=htmlspecialchars($r['created_at'])?></small></div><span class="status"><?=htmlspecialchars(ucfirst($r['status']))?></span></div><p><?=nl2br(htmlspecialchars($r['body']))?></p><form method="post" class="inline-admin"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="button secondary" name="status" value="approved">Approve</button><button class="button secondary" name="status" value="rejected">Reject</button><a href="/flavor.php?slug=<?=urlencode($r['flavor_slug'])?>" target="_blank" rel="noopener">View flavor</a></form></article><?php endforeach;?></div></section>
</main></body></html>