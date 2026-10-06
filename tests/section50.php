<?php
declare(strict_types=1);
$root=dirname(__DIR__);

$mustContain=[
    'public/index.php'=>['skip-link','newsletter-email','role="status"'],
    'public/cart.php'=>['skip-link','id="main-content"','Promo code','role="alert"'],
    'public/builder.php'=>['skip-link','role="status"','aria-live="polite"','role="spinbutton"'],
    'public/checkout.php'=>['skip-link','id="main-content"','autocomplete="email"','autocomplete="postal-code"','role="alert"'],
    'public/checkout-review.php'=>['skip-link','aria-labelledby="delivery-heading"'],
    'public/contact.php'=>['skip-link','role="status"','role="alert"'],
    'public/faq.php'=>['skip-link','<h2>Shipping & pickup</h2>'],
    'public/account.php'=>['skip-link','autocomplete="email"'],
    'public/admin.php'=>['Skip to admin content','id="admin-main"'],
    'public/assets/builder.js'=>['aria-valuenow','.minus\').disabled','.plus\').disabled'],
    'public/assets/app.css'=>['prefers-reduced-motion:reduce','min-height:44px','focus-visible'],
];
foreach($mustContain as $file=>$needles){
    $content=(string)file_get_contents($root.'/'.$file);
    foreach($needles as $needle) assert(str_contains($content,$needle),$file.' missing '.$needle);
}
echo "Section 50 checks passed\n";
