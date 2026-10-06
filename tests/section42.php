<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';

use FudgeDonuts\{Database,JobMonitorService};
$db=Database::connection();$db->exec((string)file_get_contents($root.'/database/025_job_monitoring.sql'));
$jobs=new JobMonitorService($db);
$run=$jobs->start('notifications','Transactional email delivery',5);assert($run>0);assert(count($jobs->recentRuns())===1);
$overlap=false;try{$jobs->start('notifications','Transactional email delivery',5);}catch(RuntimeException){$overlap=true;}assert($overlap);
$jobs->succeed($run,'sent=3 failed=0');$rows=$jobs->jobs();$n=array_values(array_filter($rows,fn($j)=>$j['job_key']==='notifications'))[0];assert((int)$n['consecutive_failures']===0);assert($n['stale']===false);
$run2=$jobs->start('notifications','Transactional email delivery',5);$jobs->fail($run2,'smtp unavailable');$run3=$jobs->start('notifications','Transactional email delivery',5);$jobs->fail($run3,'smtp unavailable');$run4=$jobs->start('notifications','Transactional email delivery',5);$jobs->fail($run4,'smtp unavailable');assert($jobs->health()['critical']===1);
$db->exec("UPDATE scheduled_jobs SET consecutive_failures=0,last_succeeded_at=datetime('now','-20 minutes'),last_started_at=datetime('now','-20 minutes') WHERE job_key='notifications'");assert($jobs->health()['stale']>=1);
$db->exec("INSERT INTO scheduled_job_runs(job_key,status,started_at) VALUES('operations','running',datetime('now','-3 hours'))");assert($jobs->abandonStuckRuns()===1);
echo "Section 42 checks passed\n";
