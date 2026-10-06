<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class JobMonitorService
{
    public function __construct(private readonly PDO $db) {}

    public function start(string $key,string $description,int $expectedMinutes): int
    {
        $key=$this->key($key);$description=mb_substr(trim($description),0,190);$expectedMinutes=max(1,min(10080,$expectedMinutes));
        $s=$this->db->prepare("INSERT INTO scheduled_jobs(job_key,description,expected_interval_minutes,last_started_at) VALUES(?,?,?,CURRENT_TIMESTAMP)
            ON CONFLICT(job_key) DO UPDATE SET description=excluded.description,expected_interval_minutes=excluded.expected_interval_minutes,last_started_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP");
        $s->execute([$key,$description,$expectedMinutes]);
        $r=$this->db->prepare("INSERT INTO scheduled_job_runs(job_key,status) VALUES(?,'running')");$r->execute([$key]);
        return (int)$this->db->lastInsertId();
    }

    public function succeed(int $runId,string $message=''): void
    {
        $this->finish($runId,'succeeded',$message);
    }

    public function fail(int $runId,string $message=''): void
    {
        $this->finish($runId,'failed',$message);
    }

    public function jobs(): array
    {
        $rows=$this->db->query("SELECT * FROM scheduled_jobs ORDER BY job_key")->fetchAll();
        $now=time();$out=[];
        foreach($rows as $row){
            $expected=max(1,(int)$row['expected_interval_minutes']);
            $reference=$row['last_succeeded_at']?:$row['last_started_at']?:$row['created_at'];
            $age=$reference?(max(0,$now-(strtotime((string)$reference)?:$now))):PHP_INT_MAX;
            $runningFresh=$row['last_started_at'] && (!$row['last_succeeded_at'] || strtotime((string)$row['last_started_at'])>strtotime((string)$row['last_succeeded_at'])) && $age<=($expected*60);
            $stale=!$runningFresh && $age>($expected*60*2);
            $row['age_seconds']=$age;
            $row['stale']=$stale;
            $row['running_fresh']=$runningFresh;
            $out[]=$row;
        }
        return $out;
    }

    public function recentRuns(int $limit=100): array
    {
        $limit=max(1,min(500,$limit));
        return $this->db->query("SELECT * FROM scheduled_job_runs ORDER BY id DESC LIMIT {$limit}")->fetchAll();
    }

    public function health(): array
    {
        $stale=0;$failing=0;$critical=0;
        foreach($this->jobs() as $job){
            if($job['stale'])$stale++;
            if((int)$job['consecutive_failures']>0)$failing++;
            if((int)$job['consecutive_failures']>=3)$critical++;
        }
        return ['stale'=>$stale,'failing'=>$failing,'critical'=>$critical];
    }

    public function abandonStuckRuns(): int
    {
        $s=$this->db->prepare("UPDATE scheduled_job_runs SET status='failed',finished_at=CURRENT_TIMESTAMP,message='Worker did not finish before stale-run recovery.' WHERE status='running' AND started_at<datetime('now','-2 hours')");
        $s->execute();return $s->rowCount();
    }

    private function finish(int $runId,string $status,string $message): void
    {
        $run=$this->db->prepare('SELECT job_key,started_at,status FROM scheduled_job_runs WHERE id=?');$run->execute([$runId]);$row=$run->fetch();
        if(!$row || $row['status']!=='running') throw new \InvalidArgumentException('Active job run not found.');
        $started=strtotime((string)$row['started_at'])?:time();$duration=max(0,(int)round((microtime(true)-$started)*1000));
        $message=mb_substr(trim($message),0,1000);

        $this->db->beginTransaction();
        try{
            $u=$this->db->prepare('UPDATE scheduled_job_runs SET status=?,finished_at=CURRENT_TIMESTAMP,duration_ms=?,message=? WHERE id=? AND status=\'running\'');
            $u->execute([$status,$duration,$message,$runId]);
            if($u->rowCount()!==1) throw new \RuntimeException('Job run changed before completion.');
            if($status==='succeeded'){
                $j=$this->db->prepare("UPDATE scheduled_jobs SET last_succeeded_at=CURRENT_TIMESTAMP,last_duration_ms=?,last_message=?,consecutive_failures=0,updated_at=CURRENT_TIMESTAMP WHERE job_key=?");
            }else{
                $j=$this->db->prepare("UPDATE scheduled_jobs SET last_failed_at=CURRENT_TIMESTAMP,last_duration_ms=?,last_message=?,consecutive_failures=consecutive_failures+1,updated_at=CURRENT_TIMESTAMP WHERE job_key=?");
            }
            $j->execute([$duration,$message,(string)$row['job_key']]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function key(string $key): string
    {
        $key=trim($key);
        if(!preg_match('/^[a-z0-9_-]{2,80}$/',$key)) throw new \InvalidArgumentException('Invalid scheduled job key.');
        return $key;
    }
}
