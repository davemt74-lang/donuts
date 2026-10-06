<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

header('Content-Type: text/plain; charset=UTF-8');
$base=rtrim((string)env('APP_URL','https://example.com'),'/');
echo "User-agent: *\n";
echo "Allow: /\n";
foreach([
 '/admin','/account','/cart.php','/checkout.php','/checkout-review.php','/pay.php',
 '/payment-success.php','/order-status.php','/newsletter.php','/unsubscribe.php',
 '/setup-admin.php','/stripe-webhook.php'
] as $path) echo 'Disallow: '.$path."\n";
echo 'Sitemap: '.$base."/sitemap.php\n";
