<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class ProductionPlanningService
{
    public function __construct(private readonly PDO $db) {}

    public function forecast(int $historyDays=28,int $forecastDays=7,int $safetyDays=2): array
    {
        $historyDays=max(7,min(180,$historyDays));
        $forecastDays=max(1,min(60,$forecastDays));
        $safetyDays=max(0,min(30,$safetyDays));

        $units=$this->recentFlavorUnits($historyDays);
        $rows=$this->db->query("SELECT f.id,f.name,f.slug,f.active,f.sold_out,
            COALESCE(i.track_inventory,0) track_inventory,COALESCE(i.stock_on_hand,0) stock_on_hand,
            COALESCE(i.reserved,0) reserved,COALESCE(i.low_stock_threshold,0) low_stock_threshold
            FROM flavors f LEFT JOIN flavor_inventory i ON i.flavor_id=f.id
            WHERE f.active=1 ORDER BY f.sort_order,f.name")->fetchAll();

        $out=[];$totals=['recent_units'=>0,'forecast_units'=>0,'safety_units'=>0,'suggested_prep'=>0,'at_risk'=>0];
        foreach($rows as $row){
            $id=(int)$row['id'];$recent=(int)($units[$id]??0);
            $daily=$recent/$historyDays;
            $forecast=(int)ceil($daily*$forecastDays);
            $safety=(int)ceil($daily*$safetyDays);
            $tracked=(int)$row['track_inventory']===1;
            $available=$tracked?max(0,(int)$row['stock_on_hand']-(int)$row['reserved']):null;
            $target=$forecast+$safety;
            $suggested=$tracked?max(0,$target-(int)$available):$target;
            $daysCover=($tracked && $daily>0)?round(((int)$available)/$daily,1):null;
            $risk='stable';
            if(!$tracked)$risk='untracked';
            elseif((int)$available<=0 && $daily>0)$risk='critical';
            elseif($daily>0 && $daysCover<$safetyDays)$risk='high';
            elseif($daily>0 && $daysCover<$forecastDays)$risk='medium';
            elseif($recent===0 && (int)$available<=((int)$row['low_stock_threshold']))$risk='low-stock';

            if(in_array($risk,['critical','high','medium','low-stock'],true))$totals['at_risk']++;
            $totals['recent_units']+=$recent;$totals['forecast_units']+=$forecast;$totals['safety_units']+=$safety;$totals['suggested_prep']+=$suggested;

            $out[]=[
                'flavor_id'=>$id,'name'=>(string)$row['name'],'slug'=>(string)$row['slug'],
                'tracked'=>$tracked,'stock_on_hand'=>(int)$row['stock_on_hand'],'reserved'=>(int)$row['reserved'],
                'available'=>$available,'low_stock_threshold'=>(int)$row['low_stock_threshold'],
                'recent_units'=>$recent,'daily_velocity'=>round($daily,2),'forecast_units'=>$forecast,
                'safety_units'=>$safety,'target_units'=>$target,'suggested_prep'=>$suggested,
                'days_cover'=>$daysCover,'risk'=>$risk,
            ];
        }
        usort($out,function(array $a,array $b): int {
            $rank=['critical'=>0,'high'=>1,'medium'=>2,'low-stock'=>3,'untracked'=>4,'stable'=>5];
            return ($rank[$a['risk']]<=>$rank[$b['risk']]) ?: ($b['suggested_prep']<=>$a['suggested_prep']) ?: strcmp($a['name'],$b['name']);
        });
        return ['history_days'=>$historyDays,'forecast_days'=>$forecastDays,'safety_days'=>$safetyDays,'rows'=>$out,'totals'=>$totals];
    }

    private function recentFlavorUnits(int $days): array
    {
        $s=$this->db->prepare("SELECT oi.quantity,oi.configuration_json
            FROM order_items oi JOIN orders o ON o.id=oi.order_id
            WHERE o.status NOT IN ('cancelled','payment_failed') AND o.created_at>=datetime('now',?)");
        $s->execute(['-'.$days.' days']);$totals=[];
        foreach($s->fetchAll() as $row){
            $box=json_decode((string)$row['configuration_json'],true);
            if(!is_array($box))continue;
            $mult=max(1,(int)$row['quantity']);
            foreach($box['items']??[] as $item){
                $id=(int)($item['flavor_id']??0);if($id<1)continue;
                $qty=max(0,(int)($item['quantity']??0))*$mult;
                $totals[$id]=($totals[$id]??0)+$qty;
            }
        }
        return $totals;
    }
}
