<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';

use FudgeDonuts\{Database,FinishedGoodsAgingService};

$db=Database::connection();
foreach(['001_catalog.sql','013_admin_accounts.sql','037_batch_traceability.sql','045_finished_goods_aging.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('aging@example.com','x','Aging','User','admin')");
$adminId=(int)$db->lastInsertId();$flavorId=(int)$db->query('SELECT id FROM flavors ORDER BY id LIMIT 1')->fetchColumn();

$ins=$db->prepare("INSERT INTO production_batches(batch_code,flavor_id,produced_at,best_by_date,quantity_produced,quantity_remaining,status,created_by) VALUES(?,?,datetime('now','-2 day'),?,?,?,'active',?)");
$ins->execute(['EXPIRED-75',$flavorId,gmdate('Y-m-d',time()-86400),5,5,$adminId]);$expiredId=(int)$db->lastInsertId();
$ins->execute(['SOON-75',$flavorId,gmdate('Y-m-d',time()+86400*2),4,4,$adminId]);$soonId=(int)$db->lastInsertId();
$db->exec("INSERT INTO production_batches(batch_code,flavor_id,produced_at,best_by_date,quantity_produced,quantity_remaining,status,created_by) VALUES('NODATE-75',{$flavorId},datetime('now','-1 day'),NULL,3,3,'active',{$adminId})");

$svc=new FinishedGoodsAgingService($db);
assert($svc->warningDays()===3);
$summary=$svc->summary();assert($summary['expired_batches']===1);assert($summary['expired_units']===5);assert($summary['expiring_batches']===1);assert($summary['expiring_units']===4);assert($summary['no_date_batches']===1);

assert($svc->holdExpired()===1);assert((string)$db->query("SELECT status FROM production_batches WHERE id={$expiredId}")->fetchColumn()==='hold');
assert($svc->holdExpired()===0);

$bad=false;try{$svc->disposition($soonId,1,'expired','',$adminId);}catch(InvalidArgumentException){$bad=true;}assert($bad);
$over=false;try{$svc->disposition($expiredId,6,'expired','too many',$adminId);}catch(InvalidArgumentException){$over=true;}assert($over);

$d1=$svc->disposition($expiredId,2,'expired','discarded expired units',$adminId);assert($d1>0);
assert((int)$db->query("SELECT quantity_remaining FROM production_batches WHERE id={$expiredId}")->fetchColumn()===3);
$d2=$svc->disposition($expiredId,3,'expired','discard remainder',$adminId);assert($d2>$d1);
assert((string)$db->query("SELECT status FROM production_batches WHERE id={$expiredId}")->fetchColumn()==='depleted');
assert((int)$db->query("SELECT quantity_remaining FROM production_batches WHERE id={$expiredId}")->fetchColumn()===0);

$svc->setWarningDays(1);assert($svc->warningDays()===1);assert($svc->summary()['expiring_batches']===0);
assert(count($svc->dispositions())===2);assert($svc->summary()['disposed_30d']===5);

echo "Section 75 checks passed\n";
