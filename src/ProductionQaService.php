<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class ProductionQaService
{
    public const REQUIRED_CHECKS=[
        'appearance'=>'Appearance / finish',
        'portion'=>'Portion / size',
        'allergen_label'=>'Allergen / label verification',
        'sanitation'=>'Sanitation / handling',
    ];

    public function __construct(private readonly PDO $db) {}

    public function recordCheck(int $workOrderId,string $key,string $result,string $notes,int $adminId): void
    {
        $this->assertInProgress($workOrderId);
        if(!isset(self::REQUIRED_CHECKS[$key])) throw new \InvalidArgumentException('Invalid production QA check.');
        if(!in_array($result,['pass','fail'],true)) throw new \InvalidArgumentException('QA result must be pass or fail.');
        $s=$this->db->prepare('INSERT INTO production_quality_checks(work_order_id,check_key,result,notes,checked_by) VALUES(?,?,?,?,?) ON CONFLICT(work_order_id,check_key) DO UPDATE SET result=excluded.result,notes=excluded.notes,checked_by=excluded.checked_by,checked_at=CURRENT_TIMESTAMP');
        $s->execute([$workOrderId,$key,$result,mb_substr(trim($notes),0,2000),$adminId]);
    }

    public function status(int $workOrderId): array
    {
        $checks=[];
        $s=$this->db->prepare('SELECT check_key,result,notes,checked_by,checked_at FROM production_quality_checks WHERE work_order_id=?');
        $s->execute([$workOrderId]);
        foreach($s->fetchAll() as $row)$checks[(string)$row['check_key']]=$row;

        $missing=[];$failed=[];
        foreach(self::REQUIRED_CHECKS as $key=>$label){
            if(!isset($checks[$key]))$missing[]=$key;
            elseif($checks[$key]['result']!=='pass')$failed[]=$key;
        }
        return ['ok'=>!$missing&&!$failed,'missing'=>$missing,'failed'=>$failed,'checks'=>$checks];
    }

    public function assertReadyForCompletion(int $workOrderId): void
    {
        $status=$this->status($workOrderId);
        if($status['missing']) throw new \InvalidArgumentException('Complete all required production QA checks before closing the work order.');
        if($status['failed']) throw new \InvalidArgumentException('Production QA has failed checks. Correct and re-check them before completion.');
    }

    public function recordWaste(int $workOrderId,int $quantity,string $reason,string $notes,int $adminId): int
    {
        $work=$this->workOrder($workOrderId);
        if(!in_array($work['status'],['in_progress','completed'],true)) throw new \InvalidArgumentException('Waste can only be recorded for active or completed production work.');
        if($quantity<=0 || $quantity>100000) throw new \InvalidArgumentException('Waste quantity must be between 1 and 100000.');
        if(!in_array($reason,['quality','breakage','overproduction','spoilage','setup','other'],true)) throw new \InvalidArgumentException('Invalid production waste reason.');
        $s=$this->db->prepare('INSERT INTO production_waste_events(work_order_id,flavor_id,quantity,reason,notes,recorded_by) VALUES(?,?,?,?,?,?)');
        $s->execute([$workOrderId,(int)$work['flavor_id'],$quantity,$reason,mb_substr(trim($notes),0,2000),$adminId]);
        return (int)$this->db->lastInsertId();
    }

    public function wasteForWorkOrder(int $workOrderId): array
    {
        $s=$this->db->prepare('SELECT * FROM production_waste_events WHERE work_order_id=? ORDER BY id DESC');$s->execute([$workOrderId]);return $s->fetchAll();
    }

    public function yield(int $workOrderId): array
    {
        $work=$this->workOrder($workOrderId);
        $planned=(int)$work['planned_quantity'];$actual=$work['actual_quantity']!==null?(int)$work['actual_quantity']:null;
        $waste=(int)$this->db->prepare('SELECT COALESCE(SUM(quantity),0) FROM production_waste_events WHERE work_order_id=?')->execute([$workOrderId]);
        $s=$this->db->prepare('SELECT COALESCE(SUM(quantity),0) FROM production_waste_events WHERE work_order_id=?');$s->execute([$workOrderId]);$waste=(int)$s->fetchColumn();
        $variance=$actual===null?null:$actual-$planned;
        $variancePct=($actual!==null&&$planned>0)?round(($variance/$planned)*100,1):null;
        return ['planned'=>$planned,'actual'=>$actual,'variance'=>$variance,'variance_percent'=>$variancePct,'waste'=>$waste];
    }

    public function summary(int $days=30): array
    {
        $days=max(1,min(365,$days));$since='-'.$days.' days';
        $s=$this->db->prepare("SELECT reason,COALESCE(SUM(quantity),0) qty FROM production_waste_events WHERE created_at>=datetime('now',?) GROUP BY reason ORDER BY qty DESC");$s->execute([$since]);$byReason=[];$waste=0;
        foreach($s->fetchAll() as $row){$byReason[(string)$row['reason']]=(int)$row['qty'];$waste+=(int)$row['qty'];}
        $q=$this->db->prepare("SELECT COUNT(*) FROM production_work_orders w WHERE w.status='completed' AND w.completed_at>=datetime('now',?) AND w.actual_quantity IS NOT NULL AND w.planned_quantity>0 AND ABS((w.actual_quantity-w.planned_quantity)*100.0/w.planned_quantity)>=?");
        $q->execute([$since,$this->yieldWarningPercent()]);$yieldWarnings=(int)$q->fetchColumn();
        $failed=(int)$this->db->query("SELECT COUNT(DISTINCT work_order_id) FROM production_quality_checks WHERE result='fail'")->fetchColumn();
        return ['waste_units'=>$waste,'waste_by_reason'=>$byReason,'yield_warnings'=>$yieldWarnings,'failed_qa_work_orders'=>$failed];
    }

    public function yieldWarningPercent(): int
    {
        $s=$this->db->prepare("SELECT setting_value FROM production_qa_settings WHERE setting_key='yield_warning_percent'");$s->execute();return max(1,min(100,(int)($s->fetchColumn()?:10)));
    }

    public function setYieldWarningPercent(int $percent): void
    {
        $percent=max(1,min(100,$percent));
        $s=$this->db->prepare("INSERT INTO production_qa_settings(setting_key,setting_value) VALUES('yield_warning_percent',?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=CURRENT_TIMESTAMP");
        $s->execute([(string)$percent]);
    }

    private function assertInProgress(int $id): void
    {
        $work=$this->workOrder($id);
        if($work['status']!=='in_progress') throw new \InvalidArgumentException('Production QA can only be recorded while the work order is in progress.');
    }

    private function workOrder(int $id): array
    {
        $s=$this->db->prepare('SELECT * FROM production_work_orders WHERE id=?');$s->execute([$id]);$row=$s->fetch();
        if(!$row) throw new \InvalidArgumentException('Production work order not found.');
        return $row;
    }
}
