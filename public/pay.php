<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AnalyticsService,CartService,CatalogRepository,CheckoutRecoveryService,CostAccountingService,Database,DiscountService,GiftCardService,InventoryService,NotificationService,OrderService,PackBuilderService,PaymentRepository,PresetPackService,PromotionService,ShippingService,StripeService,TaxService};

if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: /checkout-review.php');exit;}
verify_csrf($_POST['_csrf']??null);
if(empty($_SESSION['checkout'])||empty($_SESSION['fulfillment'])){header('Location: /checkout.php');exit;}

$db=Database::connection();$catalog=new CatalogRepository($db);
$cart=(new CartService(new PackBuilderService($catalog),new DiscountService($db),new PresetPackService($catalog)))->summary($_SESSION,$_SESSION['coupon']??null);
if(empty($cart['items'])){header('Location: /cart.php');exit;}

$shipping=(new ShippingService($db))->quote((string)$_SESSION['fulfillment']['code'],(string)$_SESSION['checkout']['postal_code'],$cart['total_cents']);
$inventory=new InventoryService($db);$inventory->validateCart($cart);
$orderService=new OrderService($db);
$_SESSION['checkout_attempt_token'] ??= bin2hex(random_bytes(32));
$order=$orderService->create(
    !empty($_SESSION['user_id'])?(int)$_SESSION['user_id']:null,
    $cart,
    $_SESSION['checkout'],
    $shipping,
    (string)$_SESSION['checkout_attempt_token']
);
$_SESSION['active_order_id']=(int)$order['id'];
if(!empty($_SESSION['checkout_recovery_id'])){
    try{
        (new CheckoutRecoveryService($db,(string)env('APP_KEY',''),(string)env('APP_URL','http://127.0.0.1:8080'),(int)env('CHECKOUT_RECOVERY_DAYS','7')))
            ->attachOrder((int)$_SESSION['checkout_recovery_id'],(int)$order['id']);
    }catch(Throwable $e){\FudgeDonuts\ObservabilityService::captureThrowable($e,dirname(__DIR__),'checkout_recovery_order_link_failure');}
}
if((env('ANALYTICS_ENABLED','0')??'0')==='1' && !empty($_COOKIE['fd_analytics'])){
    try{(new AnalyticsService($db))->attributeOrder((int)$order['id'],(string)$_COOKIE['fd_analytics']);}catch(Throwable){}
}

$base=rtrim((string)env('APP_URL','http://127.0.0.1:8080'),'/');
$payments=new PaymentRepository($db);$taxService=new TaxService($db);$giftCards=new GiftCardService($db,(string)env('APP_KEY',''));
$stripe=new StripeService((string)env('STRIPE_SECRET_KEY',''),(string)env('STRIPE_WEBHOOK_SECRET',''));

if(in_array((string)$order['status'],['paid','payment_review'],true)){
    $sid=(string)($order['stripe_checkout_session_id']??'');
    if($sid!==''){header('Location: '.$base.'/payment-success.php?session_id='.rawurlencode($sid),true,303);exit;}
    header('Location: /account.php',true,303);exit;
}

if((string)$order['status']==='pending_payment' && !empty($order['stripe_checkout_session_id'])){
    $existingId=(string)$order['stripe_checkout_session_id'];
    try{
        $existing=$stripe->retrieveCheckoutSession($existingId);
        $stripeStatus=(string)($existing['status']??'');
        if($stripeStatus==='open' && !empty($existing['url'])){
            header('Location: '.(string)$existing['url'],true,303);exit;
        }
        if($stripeStatus==='complete'){
            header('Location: '.$base.'/payment-success.php?session_id='.rawurlencode($existingId),true,303);exit;
        }
        if($stripeStatus==='expired'){
            $payments->markFailedByProviderSession($existingId);
            $orderService->markPaymentFailedByStripeSession($existingId,'Stripe Checkout session expired before retry.');
            $inventory->releaseOrder((int)$order['id']);$giftCards->releaseForOrder((int)$order['id']);
            $order=$orderService->preparePaymentAttempt((int)$order['id']);
        }else{
            \FudgeDonuts\HttpResponseService::send(
                503,
                'Secure checkout is temporarily unavailable.',
                'We couldn’t safely resume this payment session. Return to checkout and try again in a moment.',
                [
                    ['label'=>'Return to checkout','href'=>'/checkout-review.php'],
                    ['label'=>'Contact support','href'=>'/contact.php']
                ]
            );
        }
    }catch(Throwable $e){
        \FudgeDonuts\ObservabilityService::captureThrowable($e,dirname(__DIR__),'payment_session_verification_failure');
        \FudgeDonuts\HttpResponseService::send(
            503,
            'We couldn’t verify your payment session.',
            'For your protection, we stopped here instead of guessing about the payment state. Return to checkout or contact support if you need help.',
            [
                ['label'=>'Return to checkout','href'=>'/checkout-review.php'],
                ['label'=>'Contact support','href'=>'/contact.php']
            ],
            \FudgeDonuts\ObservabilityService::requestId()
        );
    }
}else{
    $order=$orderService->preparePaymentAttempt((int)$order['id']);
}

$holdMinutes=max(30,min(120,(int)env('CHECKOUT_HOLD_MINUTES','30')));
$expiresAtUnix=time()+($holdMinutes*60);
$expiresAtDb=gmdate('Y-m-d H:i:s',$expiresAtUnix);

$inventory->reserveOrder((int)$order['id'],$cart,$expiresAtDb);
$taxService->snapshotOrder((int)$order['id']);

$giftCardId=(int)($_SESSION['gift_card_id']??0);
$giftApplication=null;$calculatedTax=0;$stripeCharge=(int)$order['total_cents'];
try{
    if($giftCardId>0){
        $calculatedTax=$taxService->calculateExclusiveForOrder($stripe,$order);
        $taxService->recordCalculated((int)$order['id'],$calculatedTax);
        $giftApplication=$giftCards->reserveForOrder($giftCardId,(int)$order['id'],(int)$order['total_cents']+$calculatedTax);
        $stripeCharge=max(0,(int)$order['total_cents']+$calculatedTax-(int)$giftApplication['reserved_cents']);
    }
}catch(Throwable $e){
    $inventory->releaseOrder((int)$order['id']);$giftCards->releaseForOrder((int)$order['id']);
    $orderService->markPaymentInitializationFailed((int)$order['id'],'Gift card or tax calculation failed before payment initialization.');
    \FudgeDonuts\ObservabilityService::captureThrowable($e,dirname(__DIR__),'gift_card_tender_initialization_failure');
    \FudgeDonuts\HttpResponseService::send(
        503,
        'We couldn’t prepare this gift card payment.',
        'No new payment was started. Return to checkout, remove the gift card if needed, or contact support.',
        [['label'=>'Return to checkout','href'=>'/checkout-review.php'],['label'=>'Contact support','href'=>'/contact.php']],
        \FudgeDonuts\ObservabilityService::requestId()
    );
}

if($giftApplication && $stripeCharge===0){
    $sessionId='giftcard_order_'.(int)$order['id'].'_'.substr(hash('sha256',(string)$_SESSION['checkout_attempt_token']),0,12);
    try{
        $orderService->attachStripeSession((int)$order['id'],$sessionId);
        $matched=$orderService->markPaidByExternalTender($sessionId,0,(int)$giftApplication['reserved_cents'],$calculatedTax,'usd');
        if(!$matched) throw new RuntimeException('Gift card settlement did not reconcile.');
        $giftCards->redeemForOrder((int)$order['id']);
        $taxService->recordCollected((int)$order['id'],$calculatedTax);
        $inventory->commitOrder((int)$order['id']);
        (new PromotionService($db))->redeemOrder((int)$order['id']);
        (new NotificationService($db))->queueOrderConfirmation($orderService->find((int)$order['id']));
        try{(new CostAccountingService($db))->snapshotOrder((int)$order['id']);}catch(Throwable $e){\FudgeDonuts\ObservabilityService::captureThrowable($e,dirname(__DIR__),'cost_snapshot_failure');}
        if((env('ANALYTICS_ENABLED','0')??'0')==='1'){try{(new AnalyticsService($db))->recordPurchase((int)$order['id']);}catch(Throwable){}}
        try{(new CheckoutRecoveryService($db,(string)env('APP_KEY',''),(string)env('APP_URL','http://127.0.0.1:8080'),(int)env('CHECKOUT_RECOVERY_DAYS','7')))->markConvertedByOrder((int)$order['id']);}catch(Throwable){}
        header('Location: '.$base.'/payment-success.php?session_id='.rawurlencode($sessionId),true,303);exit;
    }catch(Throwable $e){
        $orderService->markSettlementReview((int)$order['id'],'Gift card-only settlement requires review.');
        $inventory->holdForReview((int)$order['id']);
        \FudgeDonuts\ObservabilityService::captureThrowable($e,dirname(__DIR__),'gift_card_only_settlement_failure');
        \FudgeDonuts\HttpResponseService::send(503,'Your payment needs review.','We held this order safely and did not create a second charge. Please contact support with your order number.',[['label'=>'Contact support','href'=>'/contact.php']],\FudgeDonuts\ObservabilityService::requestId());
    }
}

$payment=$payments->createSession(!empty($_SESSION['user_id'])?(int)$_SESSION['user_id']:null,$stripeCharge,[
    'order_id'=>$order['id'],
    'order_number'=>$order['order_number'],
    'checkout_attempt'=>hash('sha256',(string)$_SESSION['checkout_attempt_token']),
    'gift_card_cents'=>(int)($giftApplication['reserved_cents']??0),
]);

try{
  $stripeParams=[
    'mode'=>'payment',
    'success_url'=>$base.'/payment-success.php?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'=>$base.'/checkout-review.php',
    'customer_email'=>$order['email'],
    'client_reference_id'=>$order['order_number'],
    'metadata[order_id]'=>(string)$order['id'],
    'metadata[gift_card_cents]'=>(string)((int)($giftApplication['reserved_cents']??0)),
    'expires_at'=>(string)$expiresAtUnix,
    'line_items[0][quantity]'=>'1',
    'line_items[0][price_data][currency]'=>'usd',
    'line_items[0][price_data][unit_amount]'=>(string)$stripeCharge,
    'line_items[0][price_data][product_data][name]'=>$giftApplication?'Fudge Donuts order balance after gift card':'Fudge Donuts order '.$order['order_number'],
  ];
  if($giftApplication){
      $stripeParams['automatic_tax[enabled]']='false';
      $stripeParams['line_items[0][price_data][tax_behavior]']='exclusive';
  }else{
      $stripeParams=array_merge($stripeParams,$taxService->checkoutParams());
  }
  $session=$stripe->createCheckoutSession($stripeParams,$payment['idempotency_key']);
  $payments->attachProviderSession((int)$payment['id'],(string)$session['id']);
  $orderService->attachStripeSession((int)$order['id'],(string)$session['id']);
  header('Location: '.(string)$session['url'],true,303);exit;
}catch(Throwable $e){
  $inventory->releaseOrder((int)$order['id']);$giftCards->releaseForOrder((int)$order['id']);
  $payments->markFailed((int)$payment['id'],'Stripe checkout initialization failed');
  $orderService->markPaymentInitializationFailed((int)$order['id'],'Stripe checkout initialization failed');
  \FudgeDonuts\ObservabilityService::captureThrowable($e,dirname(__DIR__),'payment_checkout_initialization_failure');
  \FudgeDonuts\HttpResponseService::send(
      503,
      'Secure checkout couldn’t start.',
      'Your order is still safe. Return to checkout and try again, or contact support if the problem continues.',
      [
          ['label'=>'Return to checkout','href'=>'/checkout-review.php'],
          ['label'=>'Contact support','href'=>'/contact.php']
      ],
      \FudgeDonuts\ObservabilityService::requestId()
  );
}
