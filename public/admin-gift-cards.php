<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuditService,Database,GiftCardService};
require_admin_roles(['super_admin','admin']);

$db=Database::connection();$svc=new GiftCardService($db,(string)env('APP_KEY',''));$audit=new AdminAuditService($db);$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $id=(int)($_POST['id']??0);$status=(string)($_POST['status']??'');
        $before=$svc->card($id);$svc->setStatus($id,$status);$after=$svc->card($id);
        $audit->record((int)$_SESSION['admin_id'],'gift_card_status_changed','gift_card',$id,'Gift card status changed.',[
            'status'=>$before['status'],'last4'=>$before['code_last4']
        ],[
            'status'=>$after['status'],'last4'=>$after['code_last4']
        ]);
        $notice='Gift card status updated.';
    }catch(Throwable $e){$error=$e->getMessage();}
}
$liability=$svc->liability();$cards=$svc->recentCards(200);$purchases=$svc->purchaseStats();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Gift Cards · Fudge Donuts Admin</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-orders.php">Orders</a><a class="active" href="/admin-gift-cards.php">Gift Cards</a><a href="/admin-reports.php">Reports</a><a href="/admin-audit.php">Audit</a></nav></header>
<main class="admin-shell"><div class="admin-page-head"><div><p class="eyebrow">Stored value</p><h1>Gift Cards</h1><p class="admin-welcome">Outstanding liability, balances and card status without exposing full gift-card codes.</p></div></div>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice" role="status"><?=htmlspecialchars($notice)?></div><?php endif;?>
<section class="dashboard-kpis"><article class="kpi-card"><span>Outstanding balance</span><strong><?=money($liability['balance_cents'])?></strong><small>Stored-value liability</small></article><article class="kpi-card"><span>Available balance</span><strong><?=money($liability['available_cents'])?></strong><small>Excludes checkout reservations</small></article><article class="kpi-card"><span>Reserved</span><strong><?=money($liability['reserved_cents'])?></strong><small>Held by active orders</small></article><article class="kpi-card"><span>Cards</span><strong><?=$liability['cards']?></strong><small><?=$purchases['paid']?> paid purchases</small></article></section>
<section class="dashboard-panel"><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Card</th><th>Recipient</th><th>Issued</th><th>Balance</th><th>Reserved</th><th>Status</th><th></th></tr></thead><tbody>
<?php if(!$cards):?><tr><td colspan="7" class="empty-cell">No gift cards have been issued.</td></tr><?php endif;?>
<?php foreach($cards as $card):?><tr><td><strong>•••• <?=htmlspecialchars($card['code_last4'])?></strong><small>#<?=(int)$card['id']?></small></td><td><?=htmlspecialchars($card['recipient_email'])?></td><td><?=money((int)$card['initial_balance_cents'])?><small><?=htmlspecialchars($card['created_at'])?></small></td><td><?=money((int)$card['balance_cents'])?></td><td><?=money((int)$card['reserved_cents'])?></td><td><?=htmlspecialchars(ucfirst($card['status']))?></td><td><?php if((int)$card['balance_cents']>0):?><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="id" value="<?=(int)$card['id']?>"><input type="hidden" name="status" value="<?=$card['status']==='disabled'?'active':'disabled'?>"><button class="link"><?=$card['status']==='disabled'?'Enable':'Disable'?></button></form><?php endif;?></td></tr><?php endforeach;?>
</tbody></table></div></section>
</main></body></html>