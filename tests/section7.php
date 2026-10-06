<?php
declare(strict_types=1);$root=dirname(__DIR__);require $root.'/src/bootstrap.php';use FudgeDonuts\StripeService;
$secret='whsec_test_secret';$payload='{"id":"evt_test","type":"checkout.session.completed"}';$ts=1700000000;$sig=hash_hmac('sha256',$ts.'.'.$payload,$secret);
$s=new StripeService('sk_test_x',$secret);
assert($s->verifyWebhook($payload,'t='.$ts.',v1='.$sig,300,$ts)===true);
assert($s->verifyWebhook($payload,'t='.$ts.',v1=bad',300,$ts)===false);
assert($s->verifyWebhook($payload,'t='.$ts.',v1='.$sig,300,$ts+301)===false);
echo "Section 7 checks passed\n";
