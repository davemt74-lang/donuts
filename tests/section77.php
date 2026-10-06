<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,FinishedGoodsCycleCountService};

$db=Database::connection();
foreach(['001_catalog.sql','003_accounts.sql','006_orders.sql','013_admin_accounts.sql','037_batch_traceability.sql','045_finished_goods_aging.sql','047_finished_goods_cycle_counts.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO flavors(slug,name,description,active,sort_order) VALUES('cycle','Cycle Flavor','Cycle',1,1)");$flavor=(int)$db->lastInsertId();
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('admin@example.com','x','A','D','admin')");$admin=(int)$db->lastInsertId();
$db->exec("INSERT INTO production_batches(batch_code,flavor_id,produced_at,best_by_date,quantity_produced,quantity_remaining,status) VALUES('CYCLE-1',{$flavor},datetime('now','-1 day'),date('now','+5 days'),20,20,'active')");$batch=(int)$db->lastInsertId();

$svc=new FinishedGoodsCycleCountService($db);$q=$svc->queue();$row=array_values(array_filter($q,fn($r)=>(int)$r['id']===$batch))[0];assert($row['count_state']==='never');
$id=$svc->reconcile($batch,18,'shrinkage','Two units missing',$admin);assert($id>0);assert((int)$db->query("SELECT quantity_remaining FROM production_batches WHERE id={$batch}")->fetchColumn()===18);
$history=$svc->history();assert((int)$history[0]['variance_quantity']===-2);assert($svc->summary()['negative_variance_units_30d']===2);

$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-CYCLE','cycle-order','preparing','x@y.com','X','Y','1','P','AZ','85001','standard','Standard','shipping',1000,1000)");
$order=(int)$db->lastInsertId();$db->exec("INSERT INTO order_batch_assignments(order_id,batch_id,quantity) VALUES({$order},{$batch},5)");
$db->exec("INSERT INTO finished_goods_dispositions(batch_id,flavor_id,quantity,reason) VALUES({$batch},{$flavor},3,'damage')");
$blocked=false;try{$svc->reconcile($batch,13,'found_stock','Too high after consumed stock',$admin);}catch(InvalidArgumentException){$blocked=true;}assert($blocked);
$svc->reconcile($batch,12,'shrinkage','Count reconciled after allocations',$admin);assert((int)$db->query("SELECT quantity_remaining FROM production_batches WHERE id={$batch}")->fetchColumn()===12);
$svc->reconcile($batch,0,'shrinkage','No stock physically present',$admin);assert((string)$db->query("SELECT status FROM production_batches WHERE id={$batch}")->fetchColumn()==='depleted');
echo "Section 77 checks passed\n";
