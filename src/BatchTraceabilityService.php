<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class BatchTraceabilityService
{
    public function __construct(private readonly PDO $db) {}

    public function createBatch(array $data,int $adminId): int
    {
        $code=strtoupper(trim((string)($data['batch_code']??'')));
        $flavorId=(int)($data['flavor_id']??0);
        $produced=trim((string)($data['produced_at']??''));
        $bestBy=trim((string)($data['best_by_date']??''))?:null;
        $qty=(int)($data['quantity_produced']??0);
        $notes=mb_substr(trim((string)($data['notes']??'')),0,4000);
        if(!preg_match('/^[A-Z0-9][A-Z0-9._-]{2,63}$/',$code)) throw new \InvalidArgumentException('Batch code must be 3–64 letters, numbers, dots, dashes or underscores.');
        if(!$this->flavorExists($flavorId)) throw new \InvalidArgumentException('Flavor not found.');
        $producedTs=$produced!==''?strtotime($produced):false;
        if($producedTs===false) throw new \InvalidArgumentException('Production date/time is required.');
        if($producedTs>time()+300) throw new \InvalidArgumentException('Production date/time cannot be in the future.');
        if($bestBy!==null && strtotime($bestBy)===false) throw new \InvalidArgumentException('Best-by date is invalid.');
        if($bestBy!==null && $bestBy<gmdate('Y-m-d',$producedTs)) throw new \InvalidArgumentException('Best-by date cannot be before production date.');
        if($qty<=0) throw new \InvalidArgumentException('Produced quantity must be greater than zero.');
        try{
            $s=$this->db->prepare("INSERT INTO production_batches(batch_code,flavor_id,produced_at,best_by_date,quantity_produced,quantity_remaining,status,notes,created_by) VALUES(?,?,?,?,?,?,'active',?,?)");
            $s->execute([$code,$flavorId,$produced,$bestBy,$qty,$qty,$notes,$adminId]);
            return (int)$this->db->lastInsertId();
        }catch(\PDOException $e){
            if(str_contains(strtolower($e->getMessage()),'unique')) throw new \InvalidArgumentException('Batch code already exists.');
            throw $e;
        }
    }

    public function batches(?string $status=null): array
    {
        $where='';$params=[];
        if($status!==null&&$status!==''){
            if(!in_array($status,['active','depleted','hold','recalled'],true)) throw new \InvalidArgumentException('Invalid batch status.');
            $where=' WHERE b.status=?';$params[]=$status;
        }
        $s=$this->db->prepare("SELECT b.*,f.name flavor_name FROM production_batches b JOIN flavors f ON f.id=b.flavor_id{$where} ORDER BY b.produced_at DESC,b.id DESC");
        $s->execute($params);return $s->fetchAll();
    }

    public function batch(int $id): array
    {
        $s=$this->db->prepare('SELECT b.*,f.name flavor_name FROM production_batches b JOIN flavors f ON f.id=b.flavor_id WHERE b.id=?');
        $s->execute([$id]);$row=$s->fetch();if(!$row)throw new \InvalidArgumentException('Batch not found.');return $row;
    }

    public function requiredFlavorQuantities(int $orderId): array
    {
        $s=$this->db->prepare('SELECT quantity,configuration_json FROM order_items WHERE order_id=? ORDER BY id');$s->execute([$orderId]);
        $needed=[];
        foreach($s->fetchAll() as $item){
            $cfg=json_decode((string)$item['configuration_json'],true)?:[];$boxQty=(int)$item['quantity'];
            foreach((array)($cfg['items']??[]) as $flavor){
                $fid=(int)($flavor['flavor_id']??0);$qty=(int)($flavor['quantity']??0)*$boxQty;
                if($fid>0&&$qty>0)$needed[$fid]=($needed[$fid]??0)+$qty;
            }
        }
        ksort($needed);return $needed;
    }

    public function assignmentsForOrder(int $orderId): array
    {
        $s=$this->db->prepare('SELECT a.*,b.batch_code,b.flavor_id,b.status,b.produced_at,b.best_by_date,f.name flavor_name FROM order_batch_assignments a JOIN production_batches b ON b.id=a.batch_id JOIN flavors f ON f.id=b.flavor_id WHERE a.order_id=? ORDER BY f.name,b.batch_code');
        $s->execute([$orderId]);return $s->fetchAll();
    }

    public function assignOrder(int $orderId,array $rawAssignments,int $adminId): void
    {
        $order=$this->order($orderId);
        if(!in_array($order['status'],['preparing','ready'],true)) throw new \InvalidArgumentException('Batch assignment is only available while an order is preparing or ready.');
        if($this->assignmentsForOrder($orderId)) throw new \InvalidArgumentException('This order already has production batches assigned.');
        $needed=$this->requiredFlavorQuantities($orderId);
        if(!$needed) throw new \InvalidArgumentException('Order has no traceable flavor quantities.');

        $assignments=[];$provided=[];
        foreach($rawAssignments as $batchId=>$rawQty){
            $batchId=(int)$batchId;$qty=(int)$rawQty;if($batchId<=0||$qty<=0)continue;
            $batch=$this->batch($batchId);
            if($batch['status']!=='active') throw new \InvalidArgumentException('Only active batches can be assigned.');
            if(strtotime((string)$batch['produced_at'])>time()) throw new \InvalidArgumentException('Future-dated production batches cannot be assigned.');
            if(!empty($batch['best_by_date']) && (string)$batch['best_by_date']<gmdate('Y-m-d')) throw new \InvalidArgumentException('Expired production batches cannot be assigned.');
            if((int)$batch['quantity_remaining']<$qty) throw new \InvalidArgumentException('Batch '.$batch['batch_code'].' does not have enough remaining quantity.');
            $fid=(int)$batch['flavor_id'];$provided[$fid]=($provided[$fid]??0)+$qty;
            $assignments[]=['batch'=>$batch,'qty'=>$qty];
        }
        ksort($provided);
        if($provided!==$needed) throw new \InvalidArgumentException('Assigned batch quantities must exactly match the order flavor quantities.');

        $this->db->beginTransaction();
        try{
            $ins=$this->db->prepare('INSERT INTO order_batch_assignments(order_id,batch_id,quantity,assigned_by) VALUES(?,?,?,?)');
            $dec=$this->db->prepare("UPDATE production_batches SET quantity_remaining=quantity_remaining-?,status=CASE WHEN quantity_remaining-?=0 THEN 'depleted' ELSE status END,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='active' AND quantity_remaining>=?");
            foreach($assignments as $a){
                $qty=(int)$a['qty'];$bid=(int)$a['batch']['id'];
                $dec->execute([$qty,$qty,$bid,$qty]);if($dec->rowCount()!==1) throw new \RuntimeException('A production batch changed during assignment.');
                $ins->execute([$orderId,$bid,$qty,$adminId]);
            }
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function unassignOrder(int $orderId): void
    {
        $order=$this->order($orderId);
        if(!in_array($order['status'],['preparing','ready'],true)) throw new \InvalidArgumentException('Batch assignment can only be changed while an order is preparing or ready.');
        $assigned=$this->assignmentsForOrder($orderId);
        if(!$assigned) return;

        $this->db->beginTransaction();
        try{
            $restore=$this->db->prepare("UPDATE production_batches SET quantity_remaining=quantity_remaining+?,status=CASE WHEN status='depleted' THEN CASE WHEN best_by_date IS NOT NULL AND best_by_date<date('now') THEN 'hold' ELSE 'active' END ELSE status END,updated_at=CURRENT_TIMESTAMP WHERE id=?");
            foreach($assigned as $a)$restore->execute([(int)$a['quantity'],(int)$a['batch_id']]);
            $d=$this->db->prepare('DELETE FROM order_batch_assignments WHERE order_id=?');$d->execute([$orderId]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function setHold(int $batchId,bool $hold): void
    {
        $batch=$this->batch($batchId);
        if($batch['status']==='recalled') throw new \InvalidArgumentException('Recalled batches cannot be released.');
        if($batch['status']==='depleted') throw new \InvalidArgumentException('Depleted batches cannot be placed on hold or reactivated.');
        if(!in_array($batch['status'],['active','hold'],true)) throw new \InvalidArgumentException('Batch hold state cannot be changed.');
        if(!$hold && !empty($batch['best_by_date']) && (string)$batch['best_by_date']<gmdate('Y-m-d')) throw new \InvalidArgumentException('Expired batches cannot be released from hold.');
        $next=$hold?'hold':'active';
        $s=$this->db->prepare('UPDATE production_batches SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $s->execute([$next,$batchId]);
    }

    public function orderShipmentReady(int $orderId): array
    {
        $needed=$this->requiredFlavorQuantities($orderId);$assigned=$this->assignmentsForOrder($orderId);
        if(!$needed) return ['ok'=>true,'reason'=>''];
        if(!$assigned) return ['ok'=>false,'reason'=>'Production batches have not been assigned.'];
        $totals=[];foreach($assigned as $a){
            if(in_array($a['status'],['hold','recalled'],true)) return ['ok'=>false,'reason'=>'An assigned production batch is on hold or recalled.'];
            if(strtotime((string)$a['produced_at'])>time()) return ['ok'=>false,'reason'=>'An assigned production batch has a future production timestamp.'];
            if(!empty($a['best_by_date']) && (string)$a['best_by_date']<gmdate('Y-m-d')) return ['ok'=>false,'reason'=>'An assigned production batch is past its best-by date.'];
            $fid=(int)$a['flavor_id'];$totals[$fid]=($totals[$fid]??0)+(int)$a['quantity'];
        }
        ksort($totals);
        return $totals===$needed?['ok'=>true,'reason'=>'']:['ok'=>false,'reason'=>'Production batch quantities do not match the order.'];
    }

    public function recall(int $batchId,string $reason,int $adminId): array
    {
        $reason=mb_substr(trim($reason),0,4000);if($reason==='') throw new \InvalidArgumentException('Recall reason is required.');
        $batch=$this->batch($batchId);
        if($batch['status']==='recalled') return $this->affectedOrders($batchId);
        $s=$this->db->prepare("UPDATE production_batches SET status='recalled',recall_reason=?,recalled_at=CURRENT_TIMESTAMP,recalled_by=?,updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $s->execute([$reason,$adminId,$batchId]);
        return $this->affectedOrders($batchId);
    }

    public function affectedOrders(int $batchId): array
    {
        $s=$this->db->prepare("SELECT DISTINCT o.id,o.order_number,o.email,o.first_name,o.last_name,o.status,a.quantity FROM order_batch_assignments a JOIN orders o ON o.id=a.order_id WHERE a.batch_id=? ORDER BY o.id");
        $s->execute([$batchId]);return $s->fetchAll();
    }

    public function summary(): array
    {
        $out=['active'=>0,'depleted'=>0,'hold'=>0,'recalled'=>0,'expired_active'=>0];
        foreach($this->db->query('SELECT status,COUNT(*) count FROM production_batches GROUP BY status')->fetchAll() as $r)$out[(string)$r['status']]=(int)$r['count'];
        $out['expired_active']=(int)$this->db->query("SELECT COUNT(*) FROM production_batches WHERE status='active' AND best_by_date IS NOT NULL AND best_by_date<date('now')")->fetchColumn();
        return $out;
    }

    private function flavorExists(int $id): bool
    {
        $s=$this->db->prepare('SELECT 1 FROM flavors WHERE id=?');$s->execute([$id]);return (bool)$s->fetchColumn();
    }

    private function order(int $id): array
    {
        $s=$this->db->prepare('SELECT * FROM orders WHERE id=?');$s->execute([$id]);$row=$s->fetch();if(!$row)throw new \InvalidArgumentException('Order not found.');return $row;
    }
}
