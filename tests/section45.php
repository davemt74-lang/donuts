<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';

use FudgeDonuts\{AuthService,Database,SecurityService};

$db=Database::connection();$db->exec((string)file_get_contents($root.'/database/003_accounts.sql'));$db->exec((string)file_get_contents($root.'/database/012_security.sql'));
$auth=new AuthService($db);
$short=false;try{$auth->register('short@example.com','Password123','Short','User');}catch(InvalidArgumentException){$short=true;}assert($short);
$id=$auth->register('secure@example.com','Password1234','Secure','User');assert($id>0);

$session=['user_id'=>$id];assert(SecurityService::touchAuthSession($session,'user_id','user',120,168,1000)===true);
assert($session['user_authenticated_at']===1000);assert($session['user_last_activity']===1000);
$session=['user_id'=>$id,'user_authenticated_at'=>1000,'user_last_activity'=>1000];
assert(SecurityService::touchAuthSession($session,'user_id','user',120,168,1000+(121*60))===false);
$session=['admin_id'=>9,'admin_authenticated_at'=>1000,'admin_last_activity'=>2000];
assert(SecurityService::touchAuthSession($session,'admin_id','admin',30,12,1000+(13*3600))===false);

$csp=SecurityService::contentSecurityPolicy();
assert(str_contains($csp,"frame-ancestors 'none'"));assert(str_contains($csp,"object-src 'none'"));assert(str_contains($csp,"form-action 'self'"));
$_SERVER['HTTPS']='on';assert(SecurityService::isHttps()===true);unset($_SERVER['HTTPS']);
$_SERVER['HTTP_X_FORWARDED_PROTO']='https';putenv('TRUST_PROXY_HEADERS=0');assert(SecurityService::isHttps()===false);putenv('TRUST_PROXY_HEADERS=1');assert(SecurityService::isHttps()===true);unset($_SERVER['HTTP_X_FORWARDED_PROTO']);

SecurityService::validateCustomerPassword('long-enough-password');
$tooLong=false;try{SecurityService::validateCustomerPassword(str_repeat('a',129));}catch(InvalidArgumentException){$tooLong=true;}assert($tooLong);

$security=new SecurityService($db);$subject='user@example.com';
for($i=0;$i<5;$i++)$security->recordLoginFailure('test',$subject,5,900);
$blocked=false;try{$security->assertLoginAllowed('test',$subject,5,900);}catch(RuntimeException){$blocked=true;}assert($blocked);
$security->clearLoginFailures('test',$subject);$security->assertLoginAllowed('test',$subject,5,900);

echo "Section 45 checks passed\n";
