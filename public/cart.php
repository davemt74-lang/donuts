<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{CartService,CatalogRepository,Database,DiscountService,PackBuilderService,PresetPackService};

$catalog=new CatalogRepository(Database::connection());
$cart=new CartService(new PackBuilderService($catalog),new DiscountService(Database::connection()),new PresetPackService($catalog));

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    $action=(string)($_POST['action']??'');
    if($action==='add_pending'){
        $box=$_SESSION['pending_box']??null;
        if(!is_array($box)){http_response_code(400);exit('No pending box.');}
        $sel=[];foreach($box['items'] as $item)$sel[(int)$item['flavor_id']]=(int)$item['quantity'];
        $cart->addCustomBox($_SESSION,(int)$box['size'],$sel,1);
        unset($_SESSION['pending_box']);
        header('Location: /cart.php');exit;
    }
    if($action==='add_preset'){
        try{$cart->addPresetBox($_SESSION,(string)($_POST['slug']??''),1);}catch(Throwable $e){$_SESSION['flash']=$e->getMessage();}
        header('Location: /cart.php');exit;
    }
    if($action==='remove')$cart->remove($_SESSION,(string)($_POST['key']??''));
    if($action==='quantity')$cart->setQuantity($_SESSION,(string)($_POST['key']??''),(int)($_POST['quantity']??1));
    if($action==='coupon')$_SESSION['coupon']=strtoupper(trim((string)($_POST['code']??'')))?:null;
    header('Location: /cart.php');exit;
}
$summary=$cart->summary($_SESSION,$_SESSION['coupon']??null);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Your Cart · Fudge Donuts</title><link rel="stylesheet" href="/assets/app.css"></head><body>
<header class="nav"><a class="brand" href="/">Fudge Donuts</a><nav><a href="/">Shop</a></nav></header>
<main class="section"><p class="eyebrow">Your order</p><h1>Cart</h1><?php if(!empty($_SESSION['flash'])):?><div class="notice error"><?=htmlspecialchars((string)$_SESSION['flash'])?></div><?php unset($_SESSION['flash']);endif;?>
<?php if(!$summary['items']):?><div class="empty"><p>Your cart is empty.</p><a class="button" href="/">Choose a box</a></div>
<?php else:?><div class="cart-layout"><section>
<?php foreach($summary['items'] as $line):?><article class="cart-line">
<div><h3><?=htmlspecialchars($line['box']['type']==='preset'?$line['box']['name']:'Custom '.$line['box']['size'].' Pack')?></h3><p><?php foreach($line['box']['items'] as $i):?><a href="/flavor.php?slug=<?=urlencode($i['slug'])?>"><?=htmlspecialchars($i['name'])?></a> × <?=$i['quantity']?><?php if($i!==end($line['box']['items'])):?> · <?php endif;?><?php endforeach;?></p></div>
<form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="quantity"><input type="hidden" name="key" value="<?=htmlspecialchars($line['key'])?>"><input name="quantity" type="number" min="0" max="24" value="<?=$line['quantity']?>"><button class="link">Update</button></form>
<strong><?=money($line['line_total_cents'])?></strong>
<form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="key" value="<?=htmlspecialchars($line['key'])?>"><button class="link">Remove</button></form>
</article><?php endforeach;?>
</section><aside class="summary"><h2>Summary</h2><div><span>Subtotal</span><strong><?=money($summary['subtotal_cents'])?></strong></div>
<?php foreach($summary['discounts'] as $d):?><div class="discount"><span><?=htmlspecialchars($d['name'])?></span><strong>−<?=money($d['amount_cents'])?></strong></div><?php endforeach;?>
<div class="total"><span>Total</span><strong><?=money($summary['total_cents'])?></strong></div>
<form method="post" class="coupon"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="coupon"><input name="code" placeholder="Promo code" value="<?=htmlspecialchars((string)($_SESSION['coupon']??''))?>"><button class="button secondary">Apply</button></form>
<div class="allergen-callout compact"><strong>Before checkout</strong><span>Review ingredient and allergen information for every flavor in your order.</span></div><a class="button checkout" href="/checkout.php">Checkout</a></aside></div><?php endif;?>
</main></body></html>
