<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';

use FudgeDonuts\{Database,MarketingConsentService};

$db=Database::connection();
foreach(['003_accounts.sql','010_content.sql','022_marketing_consent.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));

$svc=new MarketingConsentService($db,'12345678901234567890123456789012','https://example.com');

$blocked=false;
try{$svc->subscribe('test@example.com','homepage',false);}catch(InvalidArgumentException){$blocked=true;}
assert($blocked);

$svc->subscribe('TEST@example.com','homepage',true);
$row=$db->query("SELECT * FROM newsletter_subscribers WHERE email='test@example.com'")->fetch();
assert($row && $row['status']==='subscribed');

$uid=(int)$db->exec("INSERT INTO users(email,password_hash,marketing_opt_in) VALUES('member@example.com','x',0)");
$svc->subscribe('member@example.com','account_profile',true);
$pref=$db->query("SELECT marketing_opt_in FROM users WHERE email='member@example.com'")->fetchColumn();
assert((int)$pref===1);

$link=$svc->unsubscribeLink('test@example.com');
parse_str((string)parse_url($link,PHP_URL_QUERY),$q);
$email=$svc->emailFromToken((string)($q['token']??''));
assert($email==='test@example.com');
$svc->unsubscribe($email,'unsubscribe_link');
$status=$db->query("SELECT status FROM newsletter_subscribers WHERE email='test@example.com'")->fetchColumn();
assert($status==='unsubscribed');

$svc->subscribe('test@example.com','homepage',true);
$status=$db->query("SELECT status FROM newsletter_subscribers WHERE email='test@example.com'")->fetchColumn();
assert($status==='subscribed');
$id=(int)$db->query("SELECT id FROM newsletter_subscribers WHERE email='test@example.com'")->fetchColumn();
$events=$svc->events($id);
assert(count($events)===3);
assert($events[0]['action']==='subscribe');
assert($events[1]['action']==='unsubscribe');

$tampered=(string)($q['token']??'').'x';
assert($svc->emailFromToken($tampered)===null);

$svc->unsubscribe('member@example.com','unsubscribe_link');
$pref=$db->query("SELECT marketing_opt_in FROM users WHERE email='member@example.com'")->fetchColumn();
assert((int)$pref===0);

$stats=$svc->stats();
assert($stats['subscribed']===1);
assert($stats['unsubscribed']===1);
assert($stats['total']===2);

echo "Section 37 checks passed\n";
