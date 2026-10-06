<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{CartService,CatalogRepository,CheckoutRecoveryService,Database,DiscountService,PackBuilderService,PresetPackService};

$id=(int)($_GET['id']??0);$expires=(int)($_GET['expires']??0);$sig=(string)($_GET['sig']??'');
$db=Database::connection();$catalog=new CatalogRepository($db);$cartService=new CartService(new PackBuilderService($catalog),new DiscountService($db),new PresetPackService($catalog));
$recovery=new CheckoutRecoveryService($db,(string)env('APP_KEY',''),(string)env('APP_URL','http://127.0.0.1:8080'),(int)env('CHECKOUT_RECOVERY_DAYS','7'));

try{
    $resolved=$recovery->resolve($id,$expires,$sig);
    $candidate=['cart'=>$resolved['cart']];
    $coupon=is_string($resolved['coupon']??null)?trim((string)$resolved['coupon']):null;
    if($coupon!=='')$candidate['coupon']=$coupon;
    $summary=$cartService->summary($candidate,$coupon?:null);
    if(empty($summary['items']))throw new RuntimeException('The recovered cart is empty.');
    $_SESSION['cart']=$resolved['cart'];
    if($coupon!==null&&$coupon!=='')$_SESSION['coupon']=$coupon;else unset($_SESSION['coupon']);
    $cartService->invalidateCheckoutAttempt($_SESSION);
    unset($_SESSION['checkout'],$_SESSION['gift_card_id'],$_SESSION['pending_box']);
    $_SESSION['checkout_recovery_id']=$id;
    $recovery->markRecovered($id);
    $_SESSION['flash']='Your box was restored and repriced using current availability.';
    header('Location: /cart.php',true,303);exit;
}catch(Throwable $e){
    \FudgeDonuts\ObservabilityService::captureThrowable($e,dirname(__DIR__),'checkout_recovery_restore_failure');
    \FudgeDonuts\HttpResponseService::send(
        410,
        'That saved checkout can’t be restored.',
        'The recovery link may have expired, or a flavor, price, discount, or box configuration has changed since you left checkout.',
        [['label'=>'Shop current boxes','href'=>'/#shop'],['label'=>'Build a new box','href'=>'/builder.php?size=12']],
        null
    );
}
