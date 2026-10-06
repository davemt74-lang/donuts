<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class IngredientProcurementPlanningService
{
    public function __construct(private readonly PDO $db) {}

    public function plan(int $historyDays=28,int $forecastDays=7,int $safetyDays=2): array
    {
        $production=(new ProductionPlanningService($this->db))->forecast($historyDays,$forecastDays,$safetyDays);
        $recipes=new RecipeService($this->db);$demand=[];$missingRecipes=[];

        foreach($production['rows'] as $row){
            $prep=(int)$row['suggested_prep'];if($prep<=0)continue;
            $recipe=$recipes->activeForFlavor((int)$row['flavor_id']);
            if(!$recipe){$missingRecipes[]=['flavor_id'=>(int)$row['flavor_id'],'name'=>(string)$row['name'],'suggested_prep'=>$prep];continue;}
            foreach($recipe['components'] as $component){
                $key=$this->key((string)$component['ingredient_name'],(string)$component['quantity_unit']);
                if(!isset($demand[$key]))$demand[$key]=[
                    'ingredient_name'=>(string)$component['ingredient_name'],
                    'quantity_unit'=>mb_strtolower((string)$component['quantity_unit']),
                    'required_quantity'=>0.0,
                    'flavors'=>[],
                ];
                $qty=round((float)$component['quantity_per_donut']*$prep,6);
                $demand[$key]['required_quantity']=round($demand[$key]['required_quantity']+$qty,6);
                $demand[$key]['flavors'][]=['flavor_id'=>(int)$row['flavor_id'],'name'=>(string)$row['name'],'prep'=>$prep,'quantity'=>$qty];
            }
        }

        $stock=$this->usableStock();$pipeline=$this->purchasePipeline();$candidates=$this->supplierCandidates();
        $rows=[];$totals=['ingredients'=>0,'shortages'=>0,'critical'=>0,'required_quantity'=>0.0,'stock_quantity'=>0.0,'pipeline_quantity'=>0.0,'to_order_quantity'=>0.0,'estimated_order_cents'=>0];

        foreach($demand as $key=>$need){
            $required=(float)$need['required_quantity'];$available=(float)($stock[$key]??0.0);
            $draft=(float)($pipeline[$key]['draft']??0.0);$ordered=(float)($pipeline[$key]['ordered']??0.0);$pipelineQty=$draft+$ordered;
            $net=max(0.0,round($required-$available-$pipelineQty,6));
            $supplier=$this->chooseSupplier($candidates[$key]??[]);
            $orderQty=0.0;$estimated=0;$risk='covered';
            if($net>0.000001){
                $totals['shortages']++;
                if(!$supplier){$risk='critical';$totals['critical']++;}
                else{
                    $minimum=$supplier['min_order_quantity']!==null?(float)$supplier['min_order_quantity']:0.0;
                    $orderQty=$this->ceilQuantity(max($net,$minimum));
                    $estimated=(int)ceil($orderQty*(int)$supplier['unit_cost_cents']);
                    $risk=(int)$supplier['lead_time_days']>$forecastDays?'high':'order';
                }
            }elseif($draft>0.000001)$risk='drafted';
            elseif($ordered>0.000001)$risk='on-order';

            $rows[]=[
                ...$need,
                'available_quantity'=>$available,
                'draft_po_quantity'=>$draft,
                'ordered_po_quantity'=>$ordered,
                'pipeline_quantity'=>$pipelineQty,
                'net_shortage'=>$net,
                'risk'=>$risk,
                'recommended_supplier_id'=>$supplier?(int)$supplier['supplier_id']:null,
                'recommended_supplier_name'=>$supplier?(string)$supplier['supplier_name']:'',
                'supplier_item_id'=>$supplier?(int)$supplier['id']:null,
                'lead_time_days'=>$supplier?(int)$supplier['lead_time_days']:null,
                'unit_cost_cents'=>$supplier?(int)$supplier['unit_cost_cents']:null,
                'min_order_quantity'=>$supplier&&$supplier['min_order_quantity']!==null?(float)$supplier['min_order_quantity']:null,
                'suggested_order_quantity'=>$orderQty,
                'estimated_order_cents'=>$estimated,
            ];
            $totals['ingredients']++;$totals['required_quantity']+=$required;$totals['stock_quantity']+=$available;$totals['pipeline_quantity']+=$pipelineQty;$totals['to_order_quantity']+=$orderQty;$totals['estimated_order_cents']+=$estimated;
        }

        $rank=['critical'=>0,'high'=>1,'order'=>2,'drafted'=>3,'on-order'=>4,'covered'=>5];
        usort($rows,fn($a,$b)=>($rank[$a['risk']]<=>$rank[$b['risk']]) ?: strcmp($a['ingredient_name'],$b['ingredient_name']));
        foreach(['required_quantity','stock_quantity','pipeline_quantity','to_order_quantity'] as $k)$totals[$k]=round((float)$totals[$k],3);

        return [
            'history_days'=>$production['history_days'],'forecast_days'=>$production['forecast_days'],'safety_days'=>$production['safety_days'],
            'rows'=>$rows,'totals'=>$totals,'missing_recipe_flavors'=>$missingRecipes,'production_totals'=>$production['totals'],
        ];
    }

    public function createRecommendedDraft(int $supplierId,int $historyDays,int $forecastDays,int $safetyDays,int $adminId): int
    {
        $plan=$this->plan($historyDays,$forecastDays,$safetyDays);$lines=[];$maxLead=0;
        foreach($plan['rows'] as $row){
            if((int)($row['recommended_supplier_id']??0)!==$supplierId || (float)$row['suggested_order_quantity']<=0)continue;
            $lines[]=['supplier_item_id'=>(int)$row['supplier_item_id'],'quantity_ordered'=>(float)$row['suggested_order_quantity']];
            $maxLead=max($maxLead,(int)$row['lead_time_days']);
        }
        if(!$lines) throw new \InvalidArgumentException('No current procurement recommendations exist for that supplier.');
        $expected=gmdate('Y-m-d',strtotime('+'.$maxLead.' days'));
        return (new SupplierPurchasingService($this->db))->createPurchaseOrder($supplierId,$lines,$expected,'Generated from ingredient procurement plan.',$adminId);
    }

    private function usableStock(): array
    {
        $sql="SELECT l.ingredient_name,l.quantity_unit,
            SUM(MAX(0,l.quantity_received-COALESCE((SELECT SUM(p.quantity_used) FROM production_batch_ingredients p WHERE p.ingredient_lot_id=l.id),0))) available
            FROM ingredient_lots l
            WHERE l.status='active' AND l.quantity_received IS NOT NULL AND (l.best_by_date IS NULL OR l.best_by_date>=date('now'))
            GROUP BY lower(l.ingredient_name),lower(l.quantity_unit)";
        $out=[];foreach($this->db->query($sql)->fetchAll() as $row)$out[$this->key((string)$row['ingredient_name'],(string)$row['quantity_unit'])]=(float)$row['available'];return $out;
    }

    private function purchasePipeline(): array
    {
        $sql="SELECT i.ingredient_name,i.quantity_unit,p.status,SUM(i.quantity_ordered-i.quantity_received) remaining
            FROM purchase_order_items i JOIN purchase_orders p ON p.id=i.purchase_order_id
            WHERE p.status IN ('draft','ordered','partially_received') AND i.quantity_received<i.quantity_ordered
            GROUP BY lower(i.ingredient_name),lower(i.quantity_unit),p.status";
        $out=[];foreach($this->db->query($sql)->fetchAll() as $row){
            $key=$this->key((string)$row['ingredient_name'],(string)$row['quantity_unit']);$out[$key]??=['draft'=>0.0,'ordered'=>0.0];
            if($row['status']==='draft')$out[$key]['draft']+=(float)$row['remaining'];else $out[$key]['ordered']+=(float)$row['remaining'];
        }return $out;
    }

    private function supplierCandidates(): array
    {
        $rows=$this->db->query("SELECT i.*,s.name supplier_name,s.active supplier_active FROM supplier_items i JOIN suppliers s ON s.id=i.supplier_id WHERE i.active=1 AND s.active=1 ORDER BY i.unit_cost_cents,i.lead_time_days,s.name")->fetchAll();
        $out=[];foreach($rows as $row)$out[$this->key((string)$row['ingredient_name'],(string)$row['quantity_unit'])][]=$row;return $out;
    }

    private function chooseSupplier(array $candidates): ?array
    {
        if(!$candidates)return null;
        usort($candidates,fn($a,$b)=>((int)$a['unit_cost_cents']<=>(int)$b['unit_cost_cents']) ?: ((int)$a['lead_time_days']<=>(int)$b['lead_time_days']) ?: strcmp((string)$a['supplier_name'],(string)$b['supplier_name']));
        return $candidates[0];
    }

    private function ceilQuantity(float $quantity): float
    {
        return ceil(($quantity-0.0000001)*1000)/1000;
    }

    private function key(string $ingredient,string $unit): string
    {
        return mb_strtolower(trim($ingredient)).'|'.mb_strtolower(trim($unit));
    }
}
