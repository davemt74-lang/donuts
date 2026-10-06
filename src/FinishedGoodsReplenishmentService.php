<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class FinishedGoodsReplenishmentService
{
    public function __construct(private readonly PDO $db) {}

    public function settings(): array
    {
        $out=['history_days'=>28,'safety_days'=>2,'minimum_run_units'=>6];
        foreach($this->db->query('SELECT setting_key,setting_value FROM finished_goods_replenishment_settings')->fetchAll() as $row){
            $out[(string)$row['setting_key']]=(int)$row['setting_value'];
        }
        return $out;
    }

    public function saveSettings(int $historyDays,int $safetyDays,int $minimumRunUnits): void
    {
        $historyDays=max(7,min(180,$historyDays));$safetyDays=max(0,min(30,$safetyDays));$minimumRunUnits=max(1,min(10000,$minimumRunUnits));
        $s=$this->db->prepare('INSERT INTO finished_goods_replenishment_settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=CURRENT_TIMESTAMP');
        foreach(['history_days'=>$historyDays,'safety_days'=>$safetyDays,'minimum_run_units'=>$minimumRunUnits] as $k=>$v)$s->execute([$k,(string)$v]);
    }

    public function recommendations(): array
    {
        $settings=$this->settings();$history=$settings['history_days'];$safetyDays=$settings['safety_days'];$minimumRun=$settings['minimum_run_units'];
        $velocity=$this->recentFlavorUnits($history);$demand=$this->openDemand();
        $safe=$this->safeFinishedGoods();$planned=$this->scheduledProduction();

        $flavors=$this->db->query("SELECT id,name,slug FROM flavors WHERE active=1 ORDER BY sort_order,name")->fetchAll();
        $rows=[];$totals=['open_demand'=>0,'safe_stock'=>0,'scheduled'=>0,'recommended'=>0,'shortage_flavors'=>0];
        foreach($flavors as $flavor){
            $id=(int)$flavor['id'];$daily=(int)($velocity[$id]??0)/$history;
            $safety=(int)ceil($daily*$safetyDays);$open=(int)($demand[$id]??0);$stock=(int)($safe[$id]??0);$scheduled=(int)($planned[$id]??0);
            $required=$open+$safety;$net=max(0,$required-$stock-$scheduled);
            $recommended=$net>0?max($minimumRun,$net):0;
            $coverage=$daily>0?round($stock/$daily,1):null;
            $risk=$net>0?($stock<$open?'critical':'replenish'):'covered';
            if($net>0)$totals['shortage_flavors']++;
            $totals['open_demand']+=$open;$totals['safe_stock']+=$stock;$totals['scheduled']+=$scheduled;$totals['recommended']+=$recommended;
            $rows[]=[
                'flavor_id'=>$id,'name'=>(string)$flavor['name'],'slug'=>(string)$flavor['slug'],
                'daily_velocity'=>round($daily,2),'safety_units'=>$safety,'open_demand'=>$open,
                'safe_stock'=>$stock,'scheduled_units'=>$scheduled,'net_shortage'=>$net,
                'recommended_units'=>$recommended,'days_cover'=>$coverage,'risk'=>$risk,
            ];
        }
        usort($rows,fn($a,$b)=>($b['recommended_units']<=>$a['recommended_units']) ?: ($b['open_demand']<=>$a['open_demand']) ?: strcmp($a['name'],$b['name']));
        return ['settings'=>$settings,'rows'=>$rows,'totals'=>$totals];
    }

    public function createWorkOrder(int $flavorId,int $quantity,string $date,int $adminId): int
    {
        $plan=$this->recommendations();$row=null;
        foreach($plan['rows'] as $candidate)if((int)$candidate['flavor_id']===$flavorId){$row=$candidate;break;}
        if(!$row) throw new \InvalidArgumentException('Flavor is not available for replenishment.');
        if((int)$row['recommended_units']<=0) throw new \InvalidArgumentException('This flavor does not currently require replenishment.');
        if($quantity<=0 || $quantity>max(100000,(int)$row['recommended_units']*5)) throw new \InvalidArgumentException('Invalid replenishment quantity.');

        $priority=$row['risk']==='critical'?'urgent':'high';
        $workId=(new ProductionSchedulingService($this->db))->create([
            'flavor_id'=>$flavorId,'scheduled_date'=>$date,'planned_quantity'=>$quantity,
            'priority'=>$priority,'assigned_to'=>'','notes'=>'Created from finished-goods replenishment recommendation.',
        ],$adminId);
        $e=$this->db->prepare("INSERT INTO finished_goods_replenishment_events(flavor_id,recommended_units,scheduled_units,work_order_id,action,notes,recorded_by) VALUES(?,?,?,?, 'work_order_created',?,?)");
        $e->execute([$flavorId,(int)$row['recommended_units'],$quantity,$workId,'Replenishment work order created.',$adminId]);
        return $workId;
    }

    public function events(int $limit=100): array
    {
        $limit=max(1,min(500,$limit));
        return $this->db->query("SELECT e.*,f.name flavor_name,w.work_order_number,a.email admin_email FROM finished_goods_replenishment_events e JOIN flavors f ON f.id=e.flavor_id LEFT JOIN production_work_orders w ON w.id=e.work_order_id LEFT JOIN admin_users a ON a.id=e.recorded_by ORDER BY e.id DESC LIMIT {$limit}")->fetchAll();
    }

    private function openDemand(): array
    {
        $s=$this->db->query("SELECT oi.quantity,oi.configuration_json FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.status IN ('paid','preparing','ready')");
        return $this->flavorUnits($s->fetchAll());
    }

    private function recentFlavorUnits(int $days): array
    {
        $s=$this->db->prepare("SELECT oi.quantity,oi.configuration_json FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.status IN ('paid','preparing','ready','shipped','delivered','completed') AND o.created_at>=datetime('now',?)");
        $s->execute(['-'.$days.' days']);return $this->flavorUnits($s->fetchAll());
    }

    private function flavorUnits(array $rows): array
    {
        $out=[];
        foreach($rows as $row){
            $box=json_decode((string)$row['configuration_json'],true);if(!is_array($box))continue;$mult=max(1,(int)$row['quantity']);
            foreach($box['items']??[] as $item){$id=(int)($item['flavor_id']??0);$qty=max(0,(int)($item['quantity']??0))*$mult;if($id>0)$out[$id]=($out[$id]??0)+$qty;}
        }
        return $out;
    }

    private function safeFinishedGoods(): array
    {
        $out=[];
        $s=$this->db->query("SELECT flavor_id,COALESCE(SUM(quantity_remaining),0) qty FROM production_batches WHERE status='active' AND quantity_remaining>0 AND produced_at<=CURRENT_TIMESTAMP AND (best_by_date IS NULL OR best_by_date>=date('now')) GROUP BY flavor_id");
        foreach($s->fetchAll() as $row)$out[(int)$row['flavor_id']]=(int)$row['qty'];return $out;
    }

    private function scheduledProduction(): array
    {
        $out=[];
        $s=$this->db->query("SELECT flavor_id,COALESCE(SUM(planned_quantity),0) qty FROM production_work_orders WHERE status IN ('planned','in_progress') GROUP BY flavor_id");
        foreach($s->fetchAll() as $row)$out[(int)$row['flavor_id']]=(int)$row['qty'];return $out;
    }
}
