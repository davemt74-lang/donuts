<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class CostAccountingService
{
    public function __construct(private readonly PDO $db) {}

    public function flavorCosts(): array
    {
        return $this->db->query("SELECT id,name,slug,unit_cost_cents FROM flavors WHERE active=1 ORDER BY sort_order,name")->fetchAll();
    }

    public function packCosts(): array
    {
        return $this->db->query("SELECT id,size,name,packaging_cost_cents FROM pack_sizes WHERE active=1 ORDER BY sort_order,size")->fetchAll();
    }

    public function setFlavorCost(int $id,int $cents): void
    {
        $s=$this->db->prepare('UPDATE flavors SET unit_cost_cents=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $s->execute([max(0,$cents),$id]);
        if($s->rowCount()===0){$q=$this->db->prepare('SELECT 1 FROM flavors WHERE id=?');$q->execute([$id]);if(!$q->fetchColumn())throw new \InvalidArgumentException('Flavor not found.');}
    }

    public function setPackCost(int $id,int $cents): void
    {
        $s=$this->db->prepare('UPDATE pack_sizes SET packaging_cost_cents=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $s->execute([max(0,$cents),$id]);
        if($s->rowCount()===0){$q=$this->db->prepare('SELECT 1 FROM pack_sizes WHERE id=?');$q->execute([$id]);if(!$q->fetchColumn())throw new \InvalidArgumentException('Pack size not found.');}
    }

    public function estimateOrder(int $orderId): array
    {
        $o=$this->db->prepare('SELECT subtotal_cents,discount_cents FROM orders WHERE id=?');$o->execute([$orderId]);$order=$o->fetch();
        if(!$order)throw new \InvalidArgumentException('Order not found.');
        $flavorCosts=[];foreach($this->db->query('SELECT id,unit_cost_cents FROM flavors')->fetchAll() as $row)$flavorCosts[(int)$row['id']]=(int)$row['unit_cost_cents'];
        $packCosts=[];foreach($this->db->query('SELECT size,packaging_cost_cents FROM pack_sizes')->fetchAll() as $row)$packCosts[(int)$row['size']]=(int)$row['packaging_cost_cents'];

        $product=0;$packaging=0;
        $s=$this->db->prepare('SELECT quantity,pack_size,configuration_json FROM order_items WHERE order_id=?');$s->execute([$orderId]);
        foreach($s->fetchAll() as $item){
            $mult=max(1,(int)$item['quantity']);$box=json_decode((string)$item['configuration_json'],true)?:[];
            foreach($box['items']??[] as $f){
                $fid=(int)($f['flavor_id']??0);$qty=max(0,(int)($f['quantity']??0));
                $product+=($flavorCosts[$fid]??0)*$qty*$mult;
            }
            $packaging+=($packCosts[(int)$item['pack_size']]??0)*$mult;
        }
        $total=$product+$packaging;
        $revenue=max(0,(int)$order['subtotal_cents']-(int)$order['discount_cents']);
        $margin=$revenue-$total;
        $pct=$revenue>0?round(($margin/$revenue)*100,2):0.0;
        return ['product_cost_cents'=>$product,'packaging_cost_cents'=>$packaging,'total_cost_cents'=>$total,'revenue_basis_cents'=>$revenue,'gross_margin_cents'=>$margin,'margin_percent'=>$pct];
    }

    public function snapshotOrder(int $orderId): array
    {
        $existing=$this->db->prepare('SELECT * FROM order_cost_snapshots WHERE order_id=?');$existing->execute([$orderId]);$row=$existing->fetch();
        if($row)return $row;
        $v=$this->estimateOrder($orderId);
        $s=$this->db->prepare('INSERT INTO order_cost_snapshots(order_id,product_cost_cents,packaging_cost_cents,total_cost_cents,revenue_basis_cents,gross_margin_cents,margin_percent) VALUES(?,?,?,?,?,?,?)');
        $s->execute([$orderId,$v['product_cost_cents'],$v['packaging_cost_cents'],$v['total_cost_cents'],$v['revenue_basis_cents'],$v['gross_margin_cents'],$v['margin_percent']]);
        return ['order_id'=>$orderId,...$v];
    }

    public function summary(?string $start=null,?string $end=null): array
    {
        $where='1=1';$params=[];
        if($start){$where.=' AND o.created_at>=?';$params[]=$start.' 00:00:00';}
        if($end){$where.=' AND o.created_at<=?';$params[]=$end.' 23:59:59';}
        $s=$this->db->prepare("SELECT COUNT(*) orders,COALESCE(SUM(c.revenue_basis_cents),0) revenue_basis_cents,COALESCE(SUM(c.total_cost_cents),0) cost_cents,COALESCE(SUM(c.gross_margin_cents),0) margin_cents FROM order_cost_snapshots c JOIN orders o ON o.id=c.order_id WHERE {$where}");
        $s->execute($params);$row=$s->fetch()?:[];$revenue=(int)($row['revenue_basis_cents']??0);$margin=(int)($row['margin_cents']??0);
        return ['orders'=>(int)($row['orders']??0),'revenue_basis_cents'=>$revenue,'cost_cents'=>(int)($row['cost_cents']??0),'margin_cents'=>$margin,'margin_percent'=>$revenue>0?round(($margin/$revenue)*100,2):0.0];
    }

    public function recent(int $limit=50): array
    {
        $limit=max(1,min(200,$limit));
        return $this->db->query("SELECT o.order_number,o.created_at,c.* FROM order_cost_snapshots c JOIN orders o ON o.id=c.order_id ORDER BY c.order_id DESC LIMIT {$limit}")->fetchAll();
    }
}
