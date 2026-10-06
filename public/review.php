<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{CatalogRepository,Database,ReviewService};
if(empty($_SESSION['user_id'])){header('Location: /account.php',true,303);exit;}

$db=Database::connection();$catalog=new CatalogRepository($db);$reviews=new ReviewService($db);$userId=(int)$_SESSION['user_id'];
$slug=trim((string)($_GET['slug']??$_POST['slug']??''));$flavor=$catalog->flavorBySlug($slug);
if(!$flavor){\FudgeDonuts\HttpResponseService::send(404,'Flavor not found.','That flavor is not currently available.',[['label'=>'See flavors','href'=>'/#flavors']]);}
if(!$reviews->canReview($userId,(int)$flavor['id'])){
    \FudgeDonuts\HttpResponseService::send(403,'Verified purchase required.','You can review this flavor after purchasing it while signed in to this account.',[['label'=>'Back to flavor','href'=>'/flavor.php?slug='.rawurlencode($slug)],['label'=>'View account','href'=>'/account.php']]);
}
$error='';$notice='';$existing=$reviews->userReview($userId,(int)$flavor['id']);
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $reviews->submit($userId,(int)$flavor['id'],(int)($_POST['rating']??0),(string)($_POST['title']??''),(string)($_POST['body']??''));
        $existing=$reviews->userReview($userId,(int)$flavor['id']);$notice='Your review was submitted for moderation.';
    }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Review <?=htmlspecialchars($flavor['name'])?> · Fudge Donuts</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body>
<a class="skip-link" href="#main-content">Skip to main content</a><header class="nav"><a class="brand" href="/">Fudge Donuts</a><nav><a href="/flavor.php?slug=<?=urlencode($slug)?>">Back to flavor</a><a href="/account.php">Account</a></nav></header>
<main id="main-content" class="section narrow"><p class="eyebrow">Verified purchase review</p><h1><?=htmlspecialchars($flavor['name'])?></h1><p>Your review will show publicly after moderation.</p>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice" role="status"><?=htmlspecialchars($notice)?></div><?php endif;?>
<?php if($existing):?><p><strong>Current status:</strong> <?=htmlspecialchars(ucfirst($existing['status']))?>. Editing it will return it to moderation.</p><?php endif;?>
<form method="post" class="admin-form review-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="slug" value="<?=htmlspecialchars($slug)?>">
<label>Rating<select name="rating" required><?php for($i=5;$i>=1;$i--):?><option value="<?=$i?>" <?=(int)($existing['rating']??5)===$i?'selected':''?>><?=$i?> star<?=$i===1?'':'s'?></option><?php endfor;?></select></label>
<label>Title <small>optional</small><input name="title" maxlength="190" value="<?=htmlspecialchars((string)($existing['title']??''))?>"></label>
<label>Review<textarea name="body" minlength="10" maxlength="3000" rows="7" required><?=htmlspecialchars((string)($existing['body']??''))?></textarea></label>
<button class="button"><?= $existing?'Update review':'Submit review' ?></button></form></main></body></html>