<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuthService,AdminDashboardService,Database,NotificationService,SecurityService};

$db=Database::connection();
$adminAuth=new AdminAuthService($db);
if(!$adminAuth->isInstalled()){header('Location: /setup-admin.php');exit;}

$error='';
if(isset($_POST['login'])){
    verify_csrf($_POST['_csrf']??null);
    $email=(string)($_POST['email']??'');
    $security=new SecurityService($db);
    try{
        $security->assertLoginAllowed('admin',$email,5,1800);
        $adminUser=$adminAuth->authenticate($email,(string)($_POST['password']??''));
        if(!$adminUser){
            $security->recordLoginFailure('admin',$email,5,1800);
            $error='Email or password is incorrect.';
        }else{
            $security->clearLoginFailures('admin',$email);
            session_regenerate_id(true);
            $_SESSION['admin']=true;
            $_SESSION['admin_id']=(int)$adminUser['id'];
            $_SESSION['admin_role']=(string)$adminUser['role'];
            header('Location: '.($adminUser['role']==='fulfillment'?'/admin-orders.php':'/admin.php'));exit;
        }
    }catch(Throwable $e){http_response_code(429);$error=$e->getMessage();}
}
if(isset($_POST['logout'])){
    verify_csrf($_POST['_csrf']??null);
    unset($_SESSION['admin'],$_SESSION['admin_id'],$_SESSION['admin_role']);
    session_regenerate_id(true);
    header('Location: /admin.php');exit;
}
if(empty($_SESSION['admin'])){
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/app.css"><title>Admin · Fudge Donuts</title></head><body class="admin-body"><main class="admin-login"><div class="admin-login-card"><p class="eyebrow">Fudge Donuts</p><h1>Store Admin</h1><p>Sign in to manage sales, orders, products and fulfillment.</p><?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><label>Email<input type="email" name="email" required autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="button" name="login">Sign in</button></form></div></main></body></html><?php exit;
}
require_admin_roles(['super_admin','admin']);

$dashboard=new AdminDashboardService($db);
$data=$dashboard->snapshot();
$change=$dashboard->percentChange($data['last_30_days']['revenue_cents'],$data['previous_30_days']['revenue_cents']);
$maxRevenue=max(1,...array_map(fn($d)=>(int)$d['revenue_cents'],$data['daily_sales']));
$adminUser=$adminAuth->admin((int)$_SESSION['admin_id']);
$mailStats=(new NotificationService($db))->stats();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dashboard · Fudge Donuts Admin</title><link rel="stylesheet" href="/assets/app.css"></head><body class="admin-body">
<header class="admin-topbar">
  <a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a>
  <nav><a class="active" href="/admin.php">Dashboard</a><a href="/admin-flavors.php">Flavors</a><a href="/admin-packs.php">Packs</a><a href="/admin-orders.php">Orders</a><a href="/admin-inventory.php">Inventory</a><a href="/admin-promotions.php">Promotions</a><a href="/admin-content.php">Content</a><a href="/admin-reports.php">Reports</a><a href="/admin-notifications.php">Email</a><?php if(admin_has_role(['super_admin'])):?><a href="/admin-users.php">Administrators</a><?php endif;?></nav>
  <form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><button class="link" name="logout">Sign out</button></form>
</header>
<main class="admin-shell">
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

  <?php if((int)$mailStats['failed']>0):?><section class="dashboard-panel email-dashboard-alert"><div><p class="eyebrow">Email delivery</p><h2><?=(int)$mailStats['failed']?> failed message<?=((int)$mailStats['failed']===1?'':'s')?> need attention</h2><p>Transactional messages have reached the retry limit.</p></div><a class="button" href="/admin-notifications.php?status=failed">Review failures</a></section><?php endif;?>
  <section class="dashboard-panel quick-panel">
    <div class="panel-head"><div><p class="eyebrow">Quick actions</p><h2>Manage the store</h2></div></div>
    <div class="quick-action-grid"><a href="/admin-orders.php"><strong>Orders</strong><span>Process new orders and update fulfillment.</span></a><a href="/admin-packs.php"><strong>Packs</strong><span>Pricing, curated boxes and flavor eligibility.</span></a><a href="/admin-inventory.php"><strong>Inventory</strong><span>Stock levels, reservations and sell-outs.</span></a><a href="/admin-promotions.php"><strong>Promotions</strong><span>Discounts, coupon codes and quantity offers.</span></a><a href="/admin-content.php"><strong>Website content</strong><span>Homepage, FAQ and SEO content.</span></a><a href="/admin-reports.php"><strong>Reports</strong><span>Detailed sales analytics and CSV export.</span></a><a href="/admin-notifications.php"><strong>Email delivery</strong><span><?=(int)$mailStats['failed']?> failed · <?=(int)$mailStats['pending']?> pending.</span></a></div>
  </section>
</main>
</body></html>