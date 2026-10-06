<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class ReportingService
{
    public function __construct(private readonly PDO $db) {}

    public function overview(?string $start=null,?string $end=null): array
    {
        [$where,$params]=$this->dateFilter($start,$end);
        $s=$this->db->prepare("SELECT
            COUNT(*) orders,
            COALESCE(SUM(total_cents),0) revenue_cents,
            COALESCE(AVG(total_cents),0) aov_cents,
            COALESCE(SUM(discount_cents),0) discount_cents,
            COALESCE(SUM(shipping_cents),0) shipping_cents,
            COALESCE(SUM(tax_cents),0) tax_cents
          FROM orders WHERE status NOT IN ('cancelled','payment_failed') {$where}");
        $s->execute($params);
        $row=$s->fetch() ?: [];
        return array_map(fn($v)=>is_numeric($v)?(int)$v:$v,$row);
    }

    public function byStatus(): array
    {
        return $this->db->query("SELECT status,COUNT(*) orders,COALESCE(SUM(total_cents),0) revenue_cents FROM orders GROUP BY status ORDER BY orders DESC")->fetchAll();
    }

    public function packPerformance(): array
    {
        return $this->db->query("SELECT oi.pack_size,SUM(oi.quantity) boxes,COALESCE(SUM(oi.line_total_cents),0) revenue_cents
            FROM order_items oi JOIN orders o ON o.id=oi.order_id
            WHERE o.status NOT IN ('cancelled','payment_failed')
            GROUP BY oi.pack_size ORDER BY oi.pack_size")->fetchAll();
    }

    public function flavorPerformance(): array
    {
        $totals=[];
        $rows=$this->db->query("SELECT oi.quantity,oi.configuration_json FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.status NOT IN ('cancelled','payment_failed')")->fetchAll();
        foreach($rows as $row){
            $box=json_decode((string)$row['configuration_json'],true);
            if(!is_array($box))continue;
            $mult=(int)$row['quantity'];
            foreach($box['items']??[] as $item){
                $name=(string)($item['name']??'Unknown');
                $totals[$name]=($totals[$name]??0)+((int)($item['quantity']??0)*$mult);
            }
        }
        arsort($totals);
        return array_map(fn($name,$qty)=>['name'=>$name,'units'=>$qty],array_keys($totals),array_values($totals));
    }

    public function dailySales(int $days=30): array
    {
        $days=max(1,min(365,$days));
        $s=$this->db->prepare("SELECT date(created_at) day,COUNT(*) orders,COALESCE(SUM(total_cents),0) revenue_cents
          FROM orders WHERE status NOT IN ('cancelled','payment_failed') AND created_at>=datetime(CURRENT_TIMESTAMP,?)
          GROUP BY date(created_at) ORDER BY day");
        $s->execute(['-'.$days.' days']);
        return $s->fetchAll();
    }

    public function csvRows(): array
    {
        return $this->db->query("SELECT order_number,created_at,status,email,fulfillment_type,subtotal_cents,discount_cents,shipping_cents,tax_cents,total_cents
          FROM orders ORDER BY id DESC")->fetchAll();
    }

    private function dateFilter(?string $start,?string $end): array
    {
        $where='';$params=[];
        if($start){$where.=' AND created_at>=?';$params[]=$start.' 00:00:00';}
        if($end){$where.=' AND created_at<=?';$params[]=$end.' 23:59:59';}
        return [$where,$params];
    }
}
