<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class AdminDashboardService
{
    public function __construct(private readonly PDO $db) {}

    public function snapshot(): array
    {
        return [
            'today'=>$this->period(date('Y-m-d'),date('Y-m-d')),
            'last_30_days'=>$this->relativePeriod(30,0),
            'previous_30_days'=>$this->relativePeriod(60,30),
            'all_time'=>$this->period(null,null),
            'fulfillment'=>$this->fulfillmentQueue(),
            'recent_orders'=>$this->recentOrders(8),
            'low_stock'=>$this->lowStock(),
            'top_packs'=>$this->topPacks(5),
            'top_flavors'=>$this->topFlavors(5),
            'promotions'=>$this->promotionPerformance(5),
            'daily_sales'=>$this->dailySales(30),
        ];
    }

    public function percentChange(int $current,int $previous): ?float
    {
        if($previous===0) return $current===0 ? 0.0 : null;
        return round((($current-$previous)/$previous)*100,1);
    }

    private function period(?string $start,?string $end): array
    {
        $where="status NOT IN ('cancelled','payment_failed')";
        $params=[];
        if($start){$where.=' AND date(created_at)>=?';$params[]=$start;}
        if($end){$where.=' AND date(created_at)<=?';$params[]=$end;}
        $s=$this->db->prepare("SELECT COUNT(*) orders,COALESCE(SUM(total_cents),0) revenue_cents,COALESCE(AVG(total_cents),0) aov_cents,COALESCE(SUM(discount_cents),0) discount_cents FROM orders WHERE {$where}");
        $s->execute($params);$row=$s->fetch()?:[];
        return [
            'orders'=>(int)($row['orders']??0),
            'revenue_cents'=>(int)($row['revenue_cents']??0),
            'aov_cents'=>(int)($row['aov_cents']??0),
            'discount_cents'=>(int)($row['discount_cents']??0),
        ];
    }

    private function relativePeriod(int $startDaysAgo,int $endDaysAgo): array
    {
        $end=new \DateTimeImmutable('today');
        $start=$end->modify('-'.$startDaysAgo.' days');
        $finish=$endDaysAgo===0?$end:$end->modify('-'.$endDaysAgo.' days');
        return $this->period($start->format('Y-m-d'),$finish->format('Y-m-d'));
    }

    private function fulfillmentQueue(): array
    {
        $statuses=['paid','preparing','ready','shipped'];
        $out=[];
        $s=$this->db->prepare('SELECT COUNT(*) FROM orders WHERE status=?');
        foreach($statuses as $status){$s->execute([$status]);$out[$status]=(int)$s->fetchColumn();}
        $out['total']=array_sum($out);
        return $out;
    }

    private function recentOrders(int $limit): array
    {
        $limit=max(1,min(20,$limit));
        return $this->db->query("SELECT id,order_number,first_name,last_name,status,fulfillment_type,total_cents,created_at FROM orders ORDER BY id DESC LIMIT {$limit}")->fetchAll();
    }

    private function lowStock(): array
    {
        return $this->db->query("SELECT f.id,f.name,i.stock_on_hand,i.reserved,(i.stock_on_hand-i.reserved) available,i.low_stock_threshold
            FROM flavor_inventory i JOIN flavors f ON f.id=i.flavor_id
            WHERE i.track_inventory=1 AND (i.stock_on_hand-i.reserved)<=i.low_stock_threshold
            ORDER BY available ASC,f.name")->fetchAll();
    }

    private function topPacks(int $limit): array
    {
        $limit=max(1,min(10,$limit));
        return $this->db->query("SELECT oi.pack_size,SUM(oi.quantity) boxes,COALESCE(SUM(oi.line_total_cents),0) revenue_cents
            FROM order_items oi JOIN orders o ON o.id=oi.order_id
            WHERE o.status NOT IN ('cancelled','payment_failed')
            GROUP BY oi.pack_size ORDER BY boxes DESC,revenue_cents DESC LIMIT {$limit}")->fetchAll();
    }

    private function topFlavors(int $limit): array
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
        $out=[];foreach(array_slice($totals,0,$limit,true) as $name=>$units)$out[]=['name'=>$name,'units'=>$units];
        return $out;
    }

    private function promotionPerformance(int $limit): array
    {
        $limit=max(1,min(10,$limit));
        return $this->db->query("SELECT dr.id,dr.name,dr.code,dr.type,dr.value,dr.usage_count,COALESCE(SUM(od.amount_cents),0) discount_cents
            FROM discount_rules dr LEFT JOIN order_discounts od ON od.discount_rule_id=dr.id
            GROUP BY dr.id,dr.name,dr.code,dr.type,dr.value,dr.usage_count
            ORDER BY dr.usage_count DESC,discount_cents DESC LIMIT {$limit}")->fetchAll();
    }

    private function dailySales(int $days): array
    {
        $days=max(7,min(90,$days));
        $start=(new \DateTimeImmutable('today'))->modify('-'.($days-1).' days');
        $s=$this->db->prepare("SELECT date(created_at) day,COALESCE(SUM(total_cents),0) revenue_cents,COUNT(*) orders
            FROM orders WHERE status NOT IN ('cancelled','payment_failed') AND date(created_at)>=?
            GROUP BY date(created_at)");
        $s->execute([$start->format('Y-m-d')]);
        $rows=[];foreach($s->fetchAll() as $r)$rows[$r['day']]=$r;
        $out=[];for($i=0;$i<$days;$i++){
            $day=$start->modify('+'.$i.' days')->format('Y-m-d');
            $out[]=['day'=>$day,'revenue_cents'=>(int)($rows[$day]['revenue_cents']??0),'orders'=>(int)($rows[$day]['orders']??0)];
        }
        return $out;
    }
}
