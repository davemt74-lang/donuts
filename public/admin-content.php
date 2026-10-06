<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{ContentService,Database};
require_admin_roles(['super_admin','admin']);
$svc=new ContentService(Database::connection());$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){verify_csrf($_POST['_csrf']??null);try{foreach((array)($_POST['content']??[]) as $k=>$v)$svc->set((string)$k,trim((string)$v));header('Location: /admin-content.php');exit;}catch(Throwable $e){$error=$e->getMessage();}}
$fields=['hero_title','hero_subtitle','story_title','story_body','seo_title','seo_description','contact_email','faq_shipping','faq_allergens','faq_gifts'];
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/app.css"><title>Content · Admin</title></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-flavors.php">Flavors</a><a href="/admin-packs.php">Packs</a><a href="/admin-orders.php">Orders</a><a href="/admin-inventory.php">Inventory</a><a href="/admin-promotions.php">Promotions</a><a class="active" href="/admin-content.php">Content</a><a href="/admin-reports.php">Reports</a></nav></header>
<main class="admin-shell narrow"><p class="eyebrow">Website</p><h1>Content</h1><?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>">
<?php foreach($fields as $field):?><label><?=htmlspecialchars(ucwords(str_replace('_',' ',$field)))?><textarea name="content[<?=htmlspecialchars($field)?>]"><?=htmlspecialchars($svc->get($field))?></textarea></label><?php endforeach;?><button class="button">Save content</button></form></main></body></html>
