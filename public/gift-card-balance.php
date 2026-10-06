<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{Database,GiftCardService,SecurityService};

$db=Database::connection();$svc=new GiftCardService($db,(string)env('APP_KEY',''));$card=null;$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $client=SecurityService::clientIdentifier();$security=new SecurityService($db);
        $security->assertLoginAllowed('gift-card-balance-ip',$client,20,3600);
        $security->recordLoginFailure('gift-card-balance-ip',$client,20,3600);
        $card=$svc->findByCode((string)($_POST['gift_card_code']??''));
        if(!$card) throw new InvalidArgumentException('Gift card was not found.');
    }catch(Throwable $e){$error=$e instanceof InvalidArgumentException?$e->getMessage():'Gift card balance could not be checked.';}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Check Gift Card Balance · Fudge Donuts</title><meta name="description" content="Check the remaining balance on a Fudge Donuts digital gift card."><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body>
<a class="skip-link" href="#main-content">Skip to main content</a><header class="nav"><a class="brand" href="/">Fudge Donuts</a><nav><a href="/gift-cards.php">Buy a Gift Card</a><a href="/cart.php">Cart</a></nav></header>
<main id="main-content" tabindex="-1" class="section narrow"><p class="eyebrow">Stored value</p><h1>Check gift card balance</h1>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?>
<?php if($card):?><div class="gift-card-balance-card" role="status"><span>Gift card ending <?=htmlspecialchars($card['code_last4'])?></span><strong><?=money((int)$card['available_cents'])?></strong><small><?=htmlspecialchars(ucfirst($card['status']))?> · <?=money((int)$card['reserved_cents'])?> currently reserved</small></div><?php endif;?>
<form method="post" class="admin-form gift-card-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><label>Gift card code<input name="gift_card_code" required autocomplete="off" placeholder="FDGC-XXXX-XXXX-XXXX-XXXX"></label><button class="button">Check balance</button></form>
<p><a href="/gift-cards.php">Buy a new gift card</a></p></main></body></html>