<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\Database;

$base=rtrim((string)env('SMOKE_BASE_URL',env('APP_URL','http://127.0.0.1:8099')),'/');
if(!extension_loaded('curl')) throw new RuntimeException('cURL extension is required for HTTP smoke tests.');

function smokeRequest(string $base,string $path): array
{
    $headers=[];
    $ch=curl_init($base.$path);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>3,
        CURLOPT_TIMEOUT=>10,
        CURLOPT_USERAGENT=>'FudgeDonutsReleaseSmoke/1.0',
        CURLOPT_HEADERFUNCTION=>static function($ch,string $line) use (&$headers): int {
            $len=strlen($line);$line=trim($line);
            if($line==='' || !str_contains($line,':')) return $len;
            [$name,$value]=explode(':',$line,2);
            $headers[strtolower(trim($name))][]=trim($value);
            return $len;
        },
    ]);
    $body=curl_exec($ch);
    if($body===false){
        $error=curl_error($ch);curl_close($ch);
        throw new RuntimeException('HTTP request failed for '.$path.': '.$error);
    }
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status'=>$status,'headers'=>$headers,'body'=>(string)$body];
}

function smokeHeader(array $response,string $name): string
{
    return implode(', ',$response['headers'][strtolower($name)]??[]);
}

function smokeAssert(bool $ok,string $message): void
{
    if(!$ok) throw new RuntimeException($message);
}

function smokePage(string $base,string $name,string $path,int $status,string $needle=''): array
{
    $r=smokeRequest($base,$path);
    smokeAssert($r['status']===$status,$name.' expected HTTP '.$status.', got '.$r['status']);
    if($needle!=='') smokeAssert(str_contains($r['body'],$needle),$name.' response body did not contain expected text.');
    smokeAssert(str_contains(strtolower(smokeHeader($r,'content-security-policy')),"default-src 'self'"),$name.' missing CSP header.');
    smokeAssert(strtolower(smokeHeader($r,'x-content-type-options'))==='nosniff',$name.' missing nosniff header.');
    echo '[PASS] '.$name.PHP_EOL;
    return $r;
}

$db=Database::connection();
$slug=(string)$db->query("SELECT slug FROM flavors WHERE active=1 ORDER BY sort_order,id LIMIT 1")->fetchColumn();
smokeAssert($slug!=='','Smoke test requires at least one active flavor.');

$home=smokePage($base,'Homepage','/',200,'Fudge Donuts');
smokeAssert(str_contains(strtolower(smokeHeader($home,'cache-control')),'private'),'Homepage must remain private because it contains session-backed newsletter state.');

$flavor=smokePage($base,'Flavor page','/flavor.php?slug='.rawurlencode($slug),200,'Fudge Donuts');
smokeAssert(str_contains(strtolower(smokeHeader($flavor,'cache-control')),'public'),'Anonymous flavor page must be public-cacheable.');
smokeAssert(str_contains(smokeHeader($flavor,'cache-control'),'max-age=300'),'Flavor cache TTL missing.');
smokeAssert(smokeHeader($flavor,'set-cookie')==='','Anonymous flavor page must not create a PHP session.');

$missingFlavor=smokePage($base,'Branded flavor 404','/flavor.php?slug=section53-missing',404,'That flavor isn’t available.');
smokeAssert(str_contains(strtolower(smokeHeader($missingFlavor,'cache-control')),'no-store'),'Error responses must be no-store.');

$faq=smokePage($base,'FAQ','/faq.php',200,'FAQ');
smokeAssert(str_contains(strtolower(smokeHeader($faq,'cache-control')),'public'),'FAQ must be public-cacheable.');
smokeAssert(smokeHeader($faq,'set-cookie')==='','FAQ must not create a PHP session.');

$builder=smokePage($base,'Pack builder','/builder.php?size=3',200,'Build your own');
smokeAssert(str_contains(strtolower(smokeHeader($builder,'cache-control')),'private'),'Builder must remain private/session-backed.');

$cart=smokePage($base,'Cart','/cart.php',200,'Cart');
smokeAssert(str_contains(strtolower(smokeHeader($cart,'cache-control')),'no-store'),'Cart must be no-store.');

$checkout=smokePage($base,'Empty checkout redirect','/checkout.php',302);
smokeAssert(smokeHeader($checkout,'location')==='/cart.php','Empty checkout must redirect to cart.');
smokeAssert(str_contains(strtolower(smokeHeader($checkout,'cache-control')),'no-store'),'Checkout redirect must be no-store.');

smokePage($base,'Customer support','/contact.php',200,'How can we help?');

$adminCount=(int)$db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
if($adminCount===0){
    smokePage($base,'Admin setup','/setup-admin.php',200,'Create your administrator');
    $admin=smokePage($base,'Admin install redirect','/admin.php',302);
    smokeAssert(smokeHeader($admin,'location')==='/setup-admin.php','Uninstalled Admin must redirect to setup.');
}else{
    $setup=smokePage($base,'Locked Admin setup','/setup-admin.php',302);
    smokeAssert(smokeHeader($setup,'location')==='/admin.php','Installed setup route must redirect to Admin.');
    smokePage($base,'Admin login','/admin.php',200,'Store Admin');
}

$health=smokePage($base,'Health endpoint','/health.php',200);
$data=json_decode($health['body'],true,512,JSON_THROW_ON_ERROR);
smokeAssert(($data['database']??'')==='ok','Health endpoint must report database=ok.');
smokeAssert(($data['status']??'')!=='unhealthy','Health endpoint must not be unhealthy.');

echo "Section 52 HTTP smoke checks passed\n";
