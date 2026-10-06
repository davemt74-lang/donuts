<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class ProductionSchedulingService
{
    public function __construct(private readonly PDO $db) {}

    public function settings(): array
    {
        $out=['daily_capacity_units'=>500,'planning_horizon_days'=>14];
        foreach($this->db->query('SELECT setting_key,setting_value FROM production_schedule_settings')->fetchAll() as $row){
            $out[(string)$row['setting_key']]=(int)$row['setting_value'];
        }
        return $out;
    }

    public function saveSettings(int $dailyCapacity,int $horizonDays): void
    {
        $dailyCapacity=max(1,min(100000,$dailyCapacity));
        $horizonDays=max(1,min(90,$horizonDays));
        $s=$this->db->prepare('INSERT INTO production_schedule_settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=CURRENT_TIMESTAMP');
        $s->execute(['daily_capacity_units',(string)$dailyCapacity]);
        $s->execute(['planning_horizon_days',(string)$horizonDays]);
    }

    public function setCapacityOverride(string $date,int $maxUnits,string $notes=''): void
    {
        $this->assertDate($date);
        $maxUnits=max(0,min(100000,$maxUnits));
        $s=$this->db->prepare('INSERT INTO production_capacity_overrides(production_date,max_units,notes) VALUES(?,?,?) ON CONFLICT(production_date) DO UPDATE SET max_units=excluded.max_units,notes=excluded.notes,updated_at=CURRENT_TIMESTAMP');
        $s->execute([$date,$maxUnits,mb_substr(trim($notes),0,1000)]);
    }

    public function capacityForDate(string $date): array
    {
        $this->assertDate($date);
        $s=$this->db->prepare('SELECT max_units,notes FROM production_capacity_overrides WHERE production_date=?');$s->execute([$date]);$override=$s->fetch();
        $settings=$this->settings();$max=$override?(int)$override['max_units']:(int)$settings['daily_capacity_units'];
        $q=$this->db->prepare("SELECT COALESCE(SUM(planned_quantity),0) FROM production_work_orders WHERE scheduled_date=? AND status IN ('planned','in_progress')");
        $q->execute([$date]);$planned=(int)$q->fetchColumn();
        return ['date'=>$date,'max_units'=>$max,'planned_units'=>$planned,'remaining_units'=>max(0,$max-$planned),'overbooked'=>$planned>$max,'notes'=>$override['notes']??'','override'=>(bool)$override];
    }

    public function create(array $data,int $adminId): int
    {
        $flavorId=(int)($data['flavor_id']??0);$date=trim((string)($data['scheduled_date']??''));$qty=(int)($data['planned_quantity']??0);
        $priority=(string)($data['priority']??'normal');$assigned=mb_substr(trim((string)($data['assigned_to']??'')),0,190);$notes=mb_substr(trim((string)($data['notes']??'')),0,4000);
        $this->assertFlavor($flavorId);$this->assertDate($date);
        if($date<gmdate('Y-m-d')) throw new \InvalidArgumentException('Production work orders cannot be scheduled in the past.');
        if($qty<=0 || $qty>100000) throw new \InvalidArgumentException('Planned quantity must be between 1 and 100000.');
        if(!in_array($priority,['normal','high','urgent'],true)) throw new \InvalidArgumentException('Invalid production priority.');

        $capacity=$this->capacityForDate($date);
        if($qty>$capacity['remaining_units']) throw new \InvalidArgumentException('This work order exceeds remaining production capacity for '.$date.'.');

        $number='WO-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
        $s=$this->db->prepare("INSERT INTO production_work_orders(work_order_number,flavor_id,scheduled_date,planned_quantity,priority,assigned_to,notes,created_by) VALUES(?,?,?,?,?,?,?,?)");
        $s->execute([$number,$flavorId,$date,$qty,$priority,$assigned,$notes,$adminId]);
        return (int)$this->db->lastInsertId();
    }

    public function start(int $id): void
    {
        $work=$this->workOrder($id);
        if($work['status']!=='planned') throw new \InvalidArgumentException('Only planned work orders can be started.');
        if((string)$work['scheduled_date']>gmdate('Y-m-d')) throw new \InvalidArgumentException('Future work orders cannot be started yet.');
        $s=$this->db->prepare("UPDATE production_work_orders SET status='in_progress',started_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='planned'");
        $s->execute([$id]);if($s->rowCount()!==1) throw new \RuntimeException('Work order changed before start.');
    }

    public function cancel(int $id): void
    {
        $work=$this->workOrder($id);
        if($work['status']!=='planned') throw new \InvalidArgumentException('Only planned work orders can be cancelled.');
        $s=$this->db->prepare("UPDATE production_work_orders SET status='cancelled',cancelled_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='planned'");
        $s->execute([$id]);
    }

    public function complete(int $id,int $adminId,int $actualQuantity,string $batchCode,?string $bestByDate=null): int
    {
        $work=$this->workOrder($id);
        if($work['status']!=='in_progress') throw new \InvalidArgumentException('Only in-progress work orders can be completed.');
        if($actualQuantity<=0 || $actualQuantity>100000) throw new \InvalidArgumentException('Actual quantity must be between 1 and 100000.');
        $batchCode=strtoupper(trim($batchCode));
        if(!preg_match('/^[A-Z0-9][A-Z0-9._-]{2,63}$/',$batchCode)) throw new \InvalidArgumentException('Batch code must be 3–64 letters, numbers, dots, dashes or underscores.');
        $bestByDate=trim((string)$bestByDate)?:null;
        if($bestByDate!==null && strtotime($bestByDate)===false) throw new \InvalidArgumentException('Best-by date is invalid.');
        if($bestByDate!==null && $bestByDate<gmdate('Y-m-d')) throw new \InvalidArgumentException('Best-by date cannot be in the past.');

        $this->db->beginTransaction();
        try{
            $b=$this->db->prepare("INSERT INTO production_batches(batch_code,flavor_id,produced_at,best_by_date,quantity_produced,quantity_remaining,status,notes,created_by) VALUES(?,?,CURRENT_TIMESTAMP,?,?,?,'active',?,?)");
            $notes='Completed from production work order '.$work['work_order_number'].($work['notes']!==''?' — '.$work['notes']:'');
            $b->execute([$batchCode,(int)$work['flavor_id'],$bestByDate,$actualQuantity,$actualQuantity,$notes,$adminId]);
            $batchId=(int)$this->db->lastInsertId();

            try{(new RecipeService($this->db))->snapshotBatch($batchId);}catch(\PDOException $e){
                $m=strtolower($e->getMessage());
                if(!str_contains($m,'flavor_recipes') && !str_contains($m,'batch_recipe_requirements')) throw $e;
            }

            $u=$this->db->prepare("UPDATE production_work_orders SET status='completed',actual_quantity=?,batch_id=?,completed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='in_progress'");
            $u->execute([$actualQuantity,$batchId,$id]);
            if($u->rowCount()!==1) throw new \RuntimeException('Work order changed before completion.');
            $this->db->commit();
            return $batchId;
        }catch(\PDOException $e){
            if($this->db->inTransaction())$this->db->rollBack();
            if(str_contains(strtolower($e->getMessage()),'unique')) throw new \InvalidArgumentException('Batch code already exists.');
            throw $e;
        }catch(\Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }

    public function workOrders(?string $status=null,int $limit=250): array
    {
        $limit=max(1,min(500,$limit));$where='';$params=[];
        if($status!==null && $status!==''){
            if(!in_array($status,['planned','in_progress','completed','cancelled'],true)) throw new \InvalidArgumentException('Invalid work order status.');
            $where=' WHERE w.status=?';$params[]=$status;
        }
        $s=$this->db->prepare("SELECT w.*,f.name flavor_name,b.batch_code FROM production_work_orders w JOIN flavors f ON f.id=w.flavor_id LEFT JOIN production_batches b ON b.id=w.batch_id{$where} ORDER BY CASE w.status WHEN 'in_progress' THEN 0 WHEN 'planned' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END,CASE w.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 ELSE 2 END,w.scheduled_date,w.id LIMIT {$limit}");
        $s->execute($params);return $s->fetchAll();
    }

    public function suggestions(?int $historyDays=null,?int $forecastDays=null,?int $safetyDays=null): array
    {
        $settings=$this->settings();$forecastDays??=$settings['planning_horizon_days'];$historyDays??=(int)\env('PRODUCTION_HISTORY_DAYS','28');$safetyDays??=(int)\env('PRODUCTION_SAFETY_DAYS','2');
        $forecast=(new ProductionPlanningService($this->db))->forecast($historyDays,$forecastDays,$safetyDays);
        $scheduled=[];
        $s=$this->db->prepare("SELECT flavor_id,COALESCE(SUM(planned_quantity),0) qty FROM production_work_orders WHERE status IN ('planned','in_progress') AND scheduled_date BETWEEN date('now') AND date('now',?) GROUP BY flavor_id");
        $s->execute(['+'.$forecastDays.' days']);
        foreach($s->fetchAll() as $row)$scheduled[(int)$row['flavor_id']]=(int)$row['qty'];
        $rows=[];
        foreach($forecast['rows'] as $row){
            $open=(int)($scheduled[(int)$row['flavor_id']]??0);
            $row['scheduled_units']=$open;
            $row['unscheduled_units']=max(0,(int)$row['suggested_prep']-$open);
            if($row['unscheduled_units']>0)$rows[]=$row;
        }
        return ['rows'=>$rows,'forecast'=>$forecast,'horizon_days'=>$forecastDays];
    }

    public function capacityCalendar(int $days=14): array
    {
        $days=max(1,min(90,$days));$rows=[];
        for($i=0;$i<$days;$i++)$rows[]=$this->capacityForDate(gmdate('Y-m-d',time()+($i*86400)));
        return $rows;
    }

    public function summary(): array
    {
        $out=['planned'=>0,'in_progress'=>0,'completed'=>0,'cancelled'=>0,'overdue'=>0,'planned_units'=>0];
        foreach($this->db->query('SELECT status,COUNT(*) count,COALESCE(SUM(planned_quantity),0) units FROM production_work_orders GROUP BY status')->fetchAll() as $row){
            $out[(string)$row['status']]=(int)$row['count'];
            if(in_array($row['status'],['planned','in_progress'],true))$out['planned_units']+=(int)$row['units'];
        }
        $out['overdue']=(int)$this->db->query("SELECT COUNT(*) FROM production_work_orders WHERE status IN ('planned','in_progress') AND scheduled_date<date('now')")->fetchColumn();
        return $out;
    }

    private function workOrder(int $id): array
    {
        $s=$this->db->prepare('SELECT w.*,f.name flavor_name FROM production_work_orders w JOIN flavors f ON f.id=w.flavor_id WHERE w.id=?');$s->execute([$id]);$row=$s->fetch();
        if(!$row) throw new \InvalidArgumentException('Production work order not found.');
        return $row;
    }

    private function assertFlavor(int $id): void
    {
        $s=$this->db->prepare('SELECT active FROM flavors WHERE id=?');$s->execute([$id]);$active=$s->fetchColumn();
        if($active===false || (int)$active!==1) throw new \InvalidArgumentException('Choose an active flavor.');
    }

    private function assertDate(string $date): void
    {
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$d || $d->format('Y-m-d')!==$date) throw new \InvalidArgumentException('Enter a valid production date.');
    }
}
