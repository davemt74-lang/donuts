<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';

use FudgeDonuts\{AuthService,Database,PasswordResetService,SecurityService};

$db=Database::connection();
foreach(['003_accounts.sql','015_customer_account.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));

assert((int)ini_get('session.sid_length')>=48);
assert(ini_get('session.use_trans_sid')==='0');

$_SERVER['HTTP_X_FORWARDED_PROTO']='https';
putenv('TRUST_PROXY_HEADERS=0');assert(SecurityService::isHttps()===false);
putenv('TRUST_PROXY_HEADERS=1');assert(SecurityService::isHttps()===true);
unset($_SERVER['HTTP_X_FORWARDED_PROTO']);

SecurityService::validateCustomerPassword('twelvechars!');
$tooLong=false;try{SecurityService::validateCustomerPassword(str_repeat('a',129));}catch(InvalidArgumentException){$tooLong=true;}assert($tooLong);

$auth=new AuthService($db);
$uid=$auth->register('section46@example.com','letters-only-password','Sec','User');
assert($uid>0);
$reset=new PasswordResetService($db);$token=$reset->create('section46@example.com');
assert($token!==null);
assert($reset->consume($token['token'],'another-long-password','another-long-password')===$uid);
assert($auth->login('section46@example.com','another-long-password')!==null);

echo "Section 46 checks passed\n";
