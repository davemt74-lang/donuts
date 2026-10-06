<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class FinishedGoodsAllocationService
{
    public function __construct(private readonly PDO $db) {}

    public function plan(int $orderId): array
    {
        $trace=new BatchTraceabilityService($this->db);
        if($trace->assignmentsForOrder($orderId)) return ['ok'=>true,'already_assigned'=>true,'assignments'=>[],'shortages'=>[]];

        $needed=$trace->requiredFlavorQuantities($orderId);
        if(!$needed) throw new \InvalidArgumentException('Order has no traceable flavor quantities.');

        $assignments=[];$shortages=[];$detail=[];
        foreach($needed as $flavorId=>$required){
            $remaining=(int)$required;$flavorAssignments=[];
            $s=$this->db->prepare("SELECT * FROM production_batches WHERE flavor_id=? AND status='active' AND quantity_remaining>0 AND produced_at<=CURRENT_TIMESTAMP AND (best_by_date IS NULL OR best_by_date>=date('now')) ORDER BY CASE WHEN best_by_date IS NULL THEN 1 ELSE 0 END,best_by_date ASC,produced_at ASC,id ASC");
            $s->execute([(int)$flavorId]);
            foreach($s->fetchAll() as $batch){
                if($remaining<=0) break;
                try{
                    $risk=(new IngredientTraceabilityService($this->db))->batchRisk((int)$batch['id']);
                    if(!$risk['ok']) continue;
                }catch(\PDOException $e){
                    $m=strtolower($e->getMessage());
                    if(!str_contains($m,'production_batch_ingredients') && !str_contains($m,'ingredient_lots') && !str_contains($m,'batch_recipe_requirements') && !str_contains($m,'flavor_recipes')) throw $e;
                }
                $take=min($remaining,(int)$batch['quantity_remaining']);
                if($take<=0) continue;
                $assignments[(int)$batch['id']]=$take;
                $flavorAssignments[]=['batch_id'=>(int)$batch['id'],'batch_code'=>(string)$batch['batch_code'],'best_by_date'=>$batch['best_by_date'],'quantity'=>$take];
                $remaining-=$take;
            }
            if($remaining>0)$shortages[(int)$flavorId]=['required'=>(int)$required,'unavailable'=>$remaining];
            $detail[(int)$flavorId]=['required'=>(int)$required,'assignments'=>$flavorAssignments,'shortage'=>$remaining];
        }
        return ['ok'=>!$shortages,'already_assigned'=>false,'assignments'=>$assignments,'shortages'=>$shortages,'detail'=>$detail];
    }

    public function assign(int $orderId,int $adminId): array
    {
        $plan=$this->plan($orderId);
        if($plan['already_assigned']) return $plan;
        if(!$plan['ok']) throw new \InvalidArgumentException('Insufficient safe finished-goods inventory for this order.');
        (new BatchTraceabilityService($this->db))->assignOrder($orderId,$plan['assignments'],$adminId,'fefo');
        return $plan;
    }

    public function queue(int $limit=100): array
    {
        $limit=max(1,min(300,$limit));
        $orders=$this->db->query("SELECT id,order_number,status,first_name,last_name,created_at FROM orders WHERE status IN ('preparing','ready') ORDER BY CASE status WHEN 'ready' THEN 0 ELSE 1 END,id ASC LIMIT {$limit}")->fetchAll();
        $trace=new BatchTraceabilityService($this->db);$out=[];
        foreach($orders as $order){
            $assigned=$trace->assignmentsForOrder((int)$order['id']);
            if($assigned){
                $order['allocation_state']='assigned';$order['shortage_units']=0;$out[]=$order;continue;
            }
            try{$plan=$this->plan((int)$order['id']);$order['allocation_state']=$plan['ok']?'allocatable':'shortage';$order['shortage_units']=array_sum(array_map(fn($s)=>(int)$s['unavailable'],$plan['shortages']));}
            catch(\Throwable){$order['allocation_state']='untraceable';$order['shortage_units']=0;}
            $out[]=$order;
        }
        return $out;
    }

    public function summary(): array
    {
        $out=['assigned'=>0,'allocatable'=>0,'shortage'=>0,'untraceable'=>0,'waiting'=>0];
        foreach($this->queue(300) as $row){
            $state=(string)$row['allocation_state'];$out[$state]=($out[$state]??0)+1;
            if($state!=='assigned')$out['waiting']++;
        }
        return $out;
    }

    public function events(int $orderId): array
    {
        $s=$this->db->prepare('SELECT * FROM finished_goods_allocation_events WHERE order_id=? ORDER BY id DESC');$s->execute([$orderId]);return $s->fetchAll();
    }
}
