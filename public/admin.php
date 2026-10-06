<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuditService,AdminAuthService,AdminDashboardService,CheckoutRecoveryService,Database,DisputeService,GiftCardService,NotificationService,ObservabilityService,ReviewService,SecurityService,SupportService};

$db=Database::connection();
$adminAuth=new AdminAuthService($db);$audit=new AdminAuditService($db);
if(!$adminAuth->isInstalled()){header('Location: /setup-admin.php');exit;}

$error='';
if(isset($_POST['login'])){
    verify_csrf($_POST['_csrf']??null);
    $email=(string)($_POST['email']??'');
    $security=new SecurityService($db);
    try{
        $client=SecurityService::clientIdentifier();
        $security->assertLoginAllowed('admin',$email,5,1800);$security->assertLoginAllowed('admin-ip',$client,20,1800);
        $adminUser=$adminAuth->authenticate($email,(string)($_POST['password']??''));
        if(!$adminUser){
            $security->recordLoginFailure('admin',$email,5,1800);$security->recordLoginFailure('admin-ip',$client,20,1800);
            $audit->record(null,'login_failed','admin',$email,'Administrator sign-in failed.',[],[],$email);
            $error='Email or password is incorrect.';
        }else{
            $security->clearLoginFailures('admin',$email);$security->clearLoginFailures('admin-ip',$client);
            session_regenerate_id(true);
            $_SESSION['admin']=true;
            $_SESSION['admin_id']=(int)$adminUser['id'];
            $_SESSION['admin_role']=(string)$adminUser['role'];
            SecurityService::initializeAuthSession($_SESSION,'admin');rotate_csrf_token();
            header('Location: '.($adminUser['role']==='fulfillment'?'/admin-orders.php':'/admin.php'));exit;
        }
    }catch(Throwable $e){http_response_code(429);$error=$e->getMessage();}
}
if(isset($_POST['logout'])){
    verify_csrf($_POST['_csrf']??null);
    $logoutAdminId=!empty($_SESSION['admin_id'])?(int)$_SESSION['admin_id']:null;
    if($logoutAdminId)$audit->record($logoutAdminId,'logout','admin',$logoutAdminId,'Administrator signed out.');
    unset($_SESSION['admin'],$_SESSION['admin_id'],$_SESSION['admin_role'],$_SESSION['admin_authenticated_at'],$_SESSION['admin_last_activity']);
    session_regenerate_id(true);rotate_csrf_token();
    header('Location: /admin.php');exit;
}
if(empty($_SESSION['admin'])){
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"><title>Admin · Fudge Donuts</title></head><body class="admin-body"><main class="admin-login"><div class="admin-login-card"><p class="eyebrow">Fudge Donuts</p><h1>Store Admin</h1><p>Sign in to manage sales, orders, products and fulfillment.</p><?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><label>Email<input type="email" name="email" required autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="button" name="login">Sign in</button></form></div></main></body></html><?php exit;
}
require_admin_roles(['super_admin','admin']);

$dashboard=new AdminDashboardService($db);
$data=$dashboard->snapshot();
$change=$dashboard->percentChange($data['last_30_days']['revenue_cents'],$data['previous_30_days']['revenue_cents']);
$maxRevenue=max(1,...array_map(fn($d)=>(int)$d['revenue_cents'],$data['daily_sales']));
$adminUser=$adminAuth->admin((int)$_SESSION['admin_id']);
$mailStats=(new NotificationService($db))->stats();$recoveryStats=(new CheckoutRecoveryService($db,(string)env('APP_KEY',''),(string)env('APP_URL','http://127.0.0.1:8080'),(int)env('CHECKOUT_RECOVERY_DAYS','7')))->stats();$supportStats=(new SupportService($db))->stats();$reviewStats=(new ReviewService($db))->stats();$disputeStats=(new DisputeService($db))->stats();$giftCardLiability=(new GiftCardService($db,(string)env('APP_KEY','')))->liability();$opsService=new ObservabilityService($db);$opsHealth=$opsService->health();$opsStats=$opsService->stats();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dashboard · Fudge Donuts Admin</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body class="admin-body">
<a class="skip-link" href="#admin-main">Skip to admin content</a>
<header class="admin-topbar">
  <a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a>
  <nav><a class="active" href="/admin.php">Dashboard</a><a href="/admin-flavors.php">Flavors</a><a href="/admin-recipes.php">Recipes</a><a href="/admin-food-compliance.php">Food Compliance</a><a href="/admin-packs.php">Packs</a><a href="/admin-orders.php">Orders</a><a href="/admin-customers.php">Customers</a><a href="/admin-disputes.php">Disputes</a><a href="/admin-gift-cards.php">Gift Cards</a><a href="/admin-support.php">Support</a><a href="/admin-reviews.php">Reviews</a><a href="/admin-inventory.php">Inventory</a><a href="/admin-ingredient-lots.php">Ingredients</a><a href="/admin-batches.php">Batches</a><a href="/admin-shipping.php">Shipping</a><a href="/admin-tax.php">Tax</a><a href="/admin-costs.php">Costs</a><a href="/admin-promotions.php">Promotions</a><a href="/admin-content.php">Content</a><a href="/admin-reports.php">Reports</a><a href="/admin-notifications.php">Email</a><a href="/admin-marketing.php">Marketing</a><a href="/admin-checkout-recovery.php">Recovery</a><a href="/admin-audit.php">Audit</a><a href="/admin-operations.php">Operations</a><?php if(admin_has_role(['super_admin'])):?><a href="/admin-backups.php">Backups</a><a href="/admin-users.php">Administrators</a><?php endif;?></nav>
  <form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><button class="link" name="logout">Sign out</button></form>
</header>
<main id="admin-main" tabindex="-1" class="admin-shell">
  <div class="admin-page-head">
    <div><p class="eyebrow">Store overview</p><h1>Dashboard</h1><p class="admin-welcome">Welcome back<?=!empty($adminUser['first_name'])?', '.htmlspecialchars($adminUser['first_name']):''?>.</p></div>
    <div class="admin-quick-actions"><a class="button secondary" href="/admin-orders.php">View orders</a><a class="button" href="/admin-flavors.php">Add flavor</a></div>
  </div>

  <section class="dashboard-kpis">
    <article class="kpi-card"><span>Sales today</span><strong><?=money($data['today']['revenue_cents'])?></strong><small><?=$data['today']['orders']?> order<?=$data['today']['orders']===1?'':'s'?></small></article>
    <article class="kpi-card"><span>Last 30 days</span><strong><?=money($data['last_30_days']['revenue_cents'])?></strong><small><?php if($change===null):?>New activity<?php elseif($change>0):?>▲ <?=$change?>% vs prior 30<?php elseif($change<0):?>▼ <?=abs($change)?>% vs prior 30<?php else:?>No change<?php endif;?></small></article>
    <article class="kpi-card"><span>Average order</span><strong><?=money($data['last_30_days']['aov_cents'])?></strong><small><?=$data['last_30_days']['orders']?> orders in 30 days</small></article>
    <article class="kpi-card"><span>All-time sales</span><strong><?=money($data['all_time']['revenue_cents'])?></strong><small><?=$data['all_time']['orders']?> orders</small></article>
  </section>

  <div class="dashboard-grid dashboard-main">
    <section class="dashboard-panel sales-panel">
      <div class="panel-head"><div><p class="eyebrow">Sales trend</p><h2>Last 30 days</h2></div><a href="/admin-reports.php">Full reports →</a></div>
      <div class="sales-chart" aria-label="30 day sales chart">
      <?php foreach($data['daily_sales'] as $day):$h=max(3,(int)round(((int)$day['revenue_cents']/$maxRevenue)*100));?>
        <div class="sales-bar-wrap" title="<?=htmlspecialchars($day['day'])?> · <?=money((int)$day['revenue_cents'])?>"><div class="sales-bar" style="height:<?=$h?>%"></div></div>
      <?php endforeach;?>
      </div>
      <div class="chart-caption"><span><?=htmlspecialchars($data['daily_sales'][0]['day'])?></span><span>Today</span></div>
    </section>

    <section class="dashboard-panel fulfillment-panel">
      <div class="panel-head"><div><p class="eyebrow">Operations</p><h2>Fulfillment queue</h2></div><a href="/admin-orders.php">Manage →</a></div>
      <div class="fulfillment-list">
        <?php foreach(['paid'=>'Paid / new','preparing'=>'Preparing','ready'=>'Ready','shipped'=>'Shipped'] as $status=>$label):?>
        <a href="/admin-orders.php?status=<?=urlencode($status)?>"><span><?=htmlspecialchars($label)?></span><strong><?=(int)$data['fulfillment'][$status]?></strong></a>
        <?php endforeach;?>
      </div>
      <div class="queue-total"><span>Active fulfillment</span><strong><?=(int)$data['fulfillment']['total']?></strong></div>
    </section>
  </div>

  <div class="dashboard-grid dashboard-secondary">
    <section class="dashboard-panel">
      <div class="panel-head"><div><p class="eyebrow">Latest activity</p><h2>Recent orders</h2></div><a href="/admin-orders.php">All orders →</a></div>
      <div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Total</th></tr></thead><tbody>
      <?php if(!$data['recent_orders']):?><tr><td colspan="4" class="empty-cell">No orders yet.</td></tr><?php endif;?>
      <?php foreach($data['recent_orders'] as $o):?><tr><td><strong><?=htmlspecialchars($o['order_number'])?></strong><small><?=htmlspecialchars($o['created_at'])?></small></td><td><?=htmlspecialchars(trim($o['first_name'].' '.$o['last_name']))?></td><td><span class="status status-<?=htmlspecialchars($o['status'])?>"><?=htmlspecialchars(str_replace('_',' ',$o['status']))?></span></td><td><strong><?=money((int)$o['total_cents'])?></strong></td></tr><?php endforeach;?>
      </tbody></table></div>
    </section>

    <section class="dashboard-panel alert-panel">
      <div class="panel-head"><div><p class="eyebrow">Inventory</p><h2>Low stock</h2></div><a href="/admin-inventory.php">Inventory →</a></div>
      <?php if(!$data['low_stock']):?><div class="dashboard-empty"><strong>Inventory looks good.</strong><span>No tracked flavors are at or below their low-stock threshold.</span></div><?php else:?><div class="stock-list">
      <?php foreach($data['low_stock'] as $item):?><div><span><strong><?=htmlspecialchars($item['name'])?></strong><small>Threshold <?=(int)$item['low_stock_threshold']?></small></span><b class="<?=(int)$item['available']<=0?'danger':''?>"><?=(int)$item['available']?> available</b></div><?php endforeach;?>
      </div><?php endif;?>
    </section>
  </div>

  <div class="dashboard-grid dashboard-thirds">
    <section class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Products</p><h2>Top flavors</h2></div></div><ol class="rank-list"><?php if(!$data['top_flavors']):?><li class="empty-cell">No sales data yet.</li><?php endif;?><?php foreach($data['top_flavors'] as $f):?><li><span><?=htmlspecialchars($f['name'])?></span><strong><?=(int)$f['units']?> donuts</strong></li><?php endforeach;?></ol></section>
    <section class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Products</p><h2>Top packs</h2></div></div><ol class="rank-list"><?php if(!$data['top_packs']):?><li class="empty-cell">No sales data yet.</li><?php endif;?><?php foreach($data['top_packs'] as $p):?><li><span><?=(int)$p['pack_size']?> Pack</span><strong><?=(int)$p['boxes']?> sold</strong></li><?php endforeach;?></ol></section>
    <section class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Marketing</p><h2>Promotions</h2></div><a href="/admin-promotions.php">Manage →</a></div><ol class="rank-list"><?php if(!$data['promotions']):?><li class="empty-cell">No promotions configured.</li><?php endif;?><?php foreach($data['promotions'] as $p):?><li><span><?=htmlspecialchars($p['name'])?><small><?=htmlspecialchars($p['code']??'Automatic')?></small></span><strong><?=(int)$p['usage_count']?> uses</strong></li><?php endforeach;?></ol></section>
  </div>

  <?php if((int)$disputeStats['open']>0):?><section class="dashboard-panel ops-dashboard-alert"><div><p class="eyebrow">Payment risk</p><h2><?=(int)$disputeStats['open']?> open Stripe dispute<?=((int)$disputeStats['open']===1?'':'s')?></h2><p><?=money((int)$disputeStats['open_amount_cents'])?> currently at risk. Fulfillment and manual refunds are blocked on affected orders.</p></div><a class="button" href="/admin-disputes.php">Review disputes</a></section><?php endif;?>
  <?php if($opsHealth['status']!=='ok'):?><section class="dashboard-panel ops-dashboard-alert"><div><p class="eyebrow">Operations</p><h2>Store health is <?=htmlspecialchars($opsHealth['status'])?></h2><p><?=(int)$opsStats['error']?> open errors · <?=(int)$opsStats['critical']?> critical · <?=(int)$opsHealth['payment_review']?> payment review.</p></div><a class="button" href="/admin-operations.php">Review operations</a></section><?php endif;?>
  <?php if((int)$mailStats['failed']>0):?><section class="dashboard-panel email-dashboard-alert"><div><p class="eyebrow">Email delivery</p><h2><?=(int)$mailStats['failed']?> failed message<?=((int)$mailStats['failed']===1?'':'s')?> need attention</h2><p>Transactional messages have reached the retry limit.</p></div><a class="button" href="/admin-notifications.php?status=failed">Review failures</a></section><?php endif;?>
  <section class="dashboard-panel quick-panel">
    <div class="panel-head"><div><p class="eyebrow">Quick actions</p><h2>Manage the store</h2></div></div>
    <div class="quick-action-grid"><a href="/admin-orders.php"><strong>Orders</strong><span>Process new orders and update fulfillment.</span></a><a href="/admin-customers.php"><strong>Customers</strong><span>Lifetime value, order history, support context, notes and tags.</span></a><a href="/admin-disputes.php"><strong>Disputes</strong><span><?=(int)$disputeStats['open']?> open · <?=money((int)$disputeStats['open_amount_cents'])?> at risk.</span></a><a href="/admin-gift-cards.php"><strong>Gift Cards</strong><span><?=money((int)$giftCardLiability['available_cents'])?> available stored value.</span></a><a href="/admin-support.php"><strong>Support</strong><span><?=(int)$supportStats['active']?> active customer request<?=((int)$supportStats['active']===1?'':'s')?>.</span></a><a href="/admin-reviews.php"><strong>Reviews</strong><span><?=(int)$reviewStats['pending']?> pending verified review<?=((int)$reviewStats['pending']===1?'':'s')?>.</span></a><a href="/admin-packs.php"><strong>Packs</strong><span>Pricing, curated boxes and flavor eligibility.</span></a><a href="/admin-food-compliance.php"><strong>Food Compliance</strong><span>Ingredients, allergens, storage, shelf life and labels.</span></a><a href="/admin-recipes.php"><strong>Recipes & BOM</strong><span>Version formulas and calculate ingredient consumption per production batch.</span></a><a href="/admin-batches.php"><strong>Batches</strong><span>Production lots, traceability, holds and recalls.</span></a><a href="/admin-inventory.php"><strong>Inventory</strong><span>Stock levels, reservations and sell-outs.</span></a><a href="/admin-ingredient-lots.php"><strong>Ingredient lots</strong><span>Supplier provenance, holds, recalls and downstream order tracing.</span></a><a href="/admin-shipping.php"><strong>Shipping</strong><span>Rates, free shipping, pickup ZIPs and customer instructions.</span></a><a href="/admin-tax.php"><strong>Tax</strong><span>Stripe Automatic Tax, tax codes and regional tax reporting.</span></a><a href="/admin-costs.php"><strong>Costs & Margin</strong><span>Flavor costs, packaging costs and gross profitability.</span></a><a href="/admin-promotions.php"><strong>Promotions</strong><span>Discounts, coupon codes and quantity offers.</span></a><a href="/admin-content.php"><strong>Website content</strong><span>Homepage, FAQ and SEO content.</span></a><a href="/admin-reports.php"><strong>Reports</strong><span>Detailed sales analytics and CSV export.</span></a><a href="/admin-checkout-recovery.php"><strong>Checkout recovery</strong><span><?=(int)$recoveryStats['active']?> active · <?=(int)$recoveryStats['converted']?> converted.</span></a><a href="/admin-notifications.php"><strong>Email delivery</strong><span><?=(int)$mailStats['failed']?> failed · <?=(int)$mailStats['pending']?> pending.</span></a><a href="/admin-operations.php"><strong>Operations</strong><span><?=htmlspecialchars(strtoupper($opsHealth['status']))?> · <?=(int)$opsStats['open']?> open events.</span></a></div>
  </section>
</main>
</body></html>