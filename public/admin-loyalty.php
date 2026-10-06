<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuditService,Database,LoyaltyService};
require_admin_roles(['super_admin','admin']);

$db=Database::connection();$svc=new LoyaltyService($db);$audit=new AdminAuditService($db);$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $action=(string)($_POST['action']??'');
        if($action==='settings'){
            $before=$svc->settings();$svc->saveSettings($_POST);$after=$svc->settings();
            $audit->record((int)$_SESSION['admin_id'],'loyalty_settings_updated','loyalty','global','Rewards program settings updated.',$before,$after);
            $notice='Rewards settings saved.';
        }elseif($action==='adjust'){
            $email=(string)($_POST['email']??'');$points=(int)($_POST['points']??0);$reason=trim((string)($_POST['reason']??''));
            if($reason==='') throw new InvalidArgumentException('A reason is required for manual rewards adjustments.');
            $after=$svc->adjustByEmail($email,$points,$reason,(int)$_SESSION['admin_id']);
            $audit->record((int)$_SESSION['admin_id'],'loyalty_points_adjusted','loyalty_account',$email,'Manual rewards adjustment.',[],['points'=>$points,'reason'=>$reason,'balance'=>(int)$after['points_balance']]);
            $notice='Rewards balance adjusted.';
        }else throw new InvalidArgumentException('Unsupported rewards action.');
    }catch(Throwable $e){$error=$e->getMessage();}
}
$settings=$svc->settings();$stats=$svc->programStats();$ledger=$svc->recentLedger(150);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Rewards · Fudge Donuts Admin</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body class="admin-body">
<a class="skip-link" href="#admin-main">Skip to admin content</a>
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-orders.php">Orders</a><a href="/admin-gift-cards.php">Gift Cards</a><a class="active" href="/admin-loyalty.php">Rewards</a><a href="/admin-reports.php">Reports</a><a href="/admin-audit.php">Audit</a></nav></header>
<main id="admin-main" tabindex="-1" class="admin-shell">
<div class="admin-page-head"><div><p class="eyebrow">Retention</p><h1>Loyalty & Rewards</h1><p class="admin-welcome">Configure earning and redemption rules, monitor points liability, and make audited account adjustments.</p></div></div>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice" role="status"><?=htmlspecialchars($notice)?></div><?php endif;?>
<section class="dashboard-kpis"><article class="kpi-card"><span>Members</span><strong><?=$stats['members']?></strong><small>Rewards accounts</small></article><article class="kpi-card"><span>Outstanding points</span><strong><?=$stats['positive_points']?></strong><small><?=money($stats['liability_cents'])?> maximum redemption value</small></article><article class="kpi-card"><span>Reserved points</span><strong><?=$stats['reserved_points']?></strong><small>Held by active checkout</small></article><article class="kpi-card"><span>Redeemed lifetime</span><strong><?=$stats['redeemed_points']?></strong><small><?=$stats['earned_points']?> points earned</small></article></section>
<section class="dashboard-grid dashboard-secondary">
<div class="dashboard-panel"><h2>Program rules</h2><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="settings">
<label class="check"><input type="checkbox" name="enabled" value="1" <?=$settings['enabled']==='1'?'checked':''?>> Enable rewards earning and redemption</label>
<label>Points earned per $1 merchandise spend<input type="number" name="points_per_dollar" min="0" max="100" value="<?=(int)$settings['points_per_dollar']?>" required></label>
<label>Redemption value per point (cents)<input type="number" name="cents_per_point" min="1" max="100" value="<?=(int)$settings['cents_per_point']?>" required></label>
<label>Minimum points per redemption<input type="number" name="minimum_redeem_points" min="0" max="100000" value="<?=(int)$settings['minimum_redeem_points']?>" required></label>
<label>Maximum merchandise percentage redeemable<input type="number" name="maximum_redeem_percent" min="1" max="100" value="<?=(int)$settings['maximum_redeem_percent']?>" required></label>
<button class="button">Save rewards settings</button></form></div>
<div class="dashboard-panel"><h2>Manual adjustment</h2><p class="muted">Use positive points for service credits and negative points for corrections. Every adjustment is recorded in both the rewards ledger and Admin audit trail.</p><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="adjust"><label>Customer email<input type="email" name="email" required autocomplete="off"></label><label>Points adjustment<input type="number" name="points" min="-100000" max="100000" required></label><label>Reason<textarea name="reason" maxlength="500" required rows="4"></textarea></label><button class="button secondary">Apply audited adjustment</button></form></div>
</section>
<section class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Ledger</p><h2>Recent rewards activity</h2></div></div><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Customer</th><th>Activity</th><th>Points</th><th>Balance</th><th>Date</th></tr></thead><tbody><?php if(!$ledger):?><tr><td colspan="5" class="empty-cell">No rewards activity yet.</td></tr><?php endif;?><?php foreach($ledger as $entry):?><tr><td><?=htmlspecialchars($entry['email'])?></td><td><?=htmlspecialchars(ucwords(str_replace('_',' ',$entry['entry_type'])))?><small><?=htmlspecialchars($entry['note'])?></small></td><td class="<?=((int)$entry['points']>=0?'rewards-positive':'rewards-negative')?>"><?=((int)$entry['points']>0?'+':'')?><?=(int)$entry['points']?></td><td><?=(int)$entry['balance_after_points']?></td><td><?=htmlspecialchars($entry['created_at'])?></td></tr><?php endforeach;?></tbody></table></div></section>
</main></body></html>