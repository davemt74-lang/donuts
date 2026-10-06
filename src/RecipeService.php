<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class RecipeService
{
    public function __construct(private readonly PDO $db) {}

    public function createVersion(int $flavorId,array $components,string $notes,int $adminId,bool $activate=true): int
    {
        if(!$this->flavorExists($flavorId)) throw new \InvalidArgumentException('Flavor not found.');
        $clean=$this->normalizeComponents($components);
        if(!$clean) throw new \InvalidArgumentException('A recipe must include at least one ingredient.');

        $this->db->beginTransaction();
        try{
            $q=$this->db->prepare('SELECT COALESCE(MAX(version),0)+1 FROM flavor_recipes WHERE flavor_id=?');$q->execute([$flavorId]);$version=(int)$q->fetchColumn();
            $s=$this->db->prepare("INSERT INTO flavor_recipes(flavor_id,version,status,notes,created_by) VALUES(?,?,'draft',?,?)");
            $s->execute([$flavorId,$version,mb_substr(trim($notes),0,4000),$adminId]);$id=(int)$this->db->lastInsertId();
            $i=$this->db->prepare('INSERT INTO flavor_recipe_components(recipe_id,ingredient_name,quantity_per_donut,quantity_unit,sort_order) VALUES(?,?,?,?,?)');
            foreach($clean as $idx=>$c)$i->execute([$id,$c['ingredient_name'],$c['quantity_per_donut'],$c['quantity_unit'],$idx]);
            if($activate)$this->activateWithinTransaction($id,$flavorId);
            $this->db->commit();return $id;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function activate(int $recipeId): void
    {
        $recipe=$this->recipe($recipeId);
        if($this->recipeUsedByBatch($recipeId) && $recipe['status']==='retired') throw new \InvalidArgumentException('A historical retired recipe cannot be reactivated after production use.');
        $this->db->beginTransaction();
        try{$this->activateWithinTransaction($recipeId,(int)$recipe['flavor_id']);$this->db->commit();}
        catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function recipe(int $id): array
    {
        $s=$this->db->prepare('SELECT r.*,f.name flavor_name FROM flavor_recipes r JOIN flavors f ON f.id=r.flavor_id WHERE r.id=?');$s->execute([$id]);$row=$s->fetch();
        if(!$row) throw new \InvalidArgumentException('Recipe not found.');
        $row['components']=$this->components($id);return $row;
    }

    public function recipesForFlavor(int $flavorId): array
    {
        $s=$this->db->prepare('SELECT r.*,f.name flavor_name,(SELECT COUNT(*) FROM batch_recipe_requirements b WHERE b.recipe_id=r.id) batch_count FROM flavor_recipes r JOIN flavors f ON f.id=r.flavor_id WHERE r.flavor_id=? ORDER BY r.version DESC');
        $s->execute([$flavorId]);return $s->fetchAll();
    }

    public function activeForFlavor(int $flavorId): ?array
    {
        $s=$this->db->prepare("SELECT id FROM flavor_recipes WHERE flavor_id=? AND status='active'");$s->execute([$flavorId]);$id=$s->fetchColumn();
        return $id?$this->recipe((int)$id):null;
    }

    public function components(int $recipeId): array
    {
        $s=$this->db->prepare('SELECT * FROM flavor_recipe_components WHERE recipe_id=? ORDER BY sort_order,id');$s->execute([$recipeId]);return $s->fetchAll();
    }

    public function snapshotBatch(int $batchId): bool
    {
        $batch=$this->batch($batchId);
        $existing=$this->db->prepare('SELECT COUNT(*) FROM batch_recipe_requirements WHERE batch_id=?');$existing->execute([$batchId]);
        if((int)$existing->fetchColumn()>0) return true;
        $recipe=$this->activeForFlavor((int)$batch['flavor_id']);if(!$recipe)return false;

        $s=$this->db->prepare('INSERT INTO batch_recipe_requirements(batch_id,recipe_id,recipe_version,ingredient_name,expected_quantity,quantity_unit) VALUES(?,?,?,?,?,?)');
        foreach($recipe['components'] as $c){
            $expected=round((float)$c['quantity_per_donut']*(int)$batch['quantity_produced'],6);
            if($expected<=0) throw new \RuntimeException('Recipe produced an invalid batch ingredient requirement.');
            $s->execute([$batchId,(int)$recipe['id'],(int)$recipe['version'],$c['ingredient_name'],$expected,$c['quantity_unit']]);
        }
        return true;
    }

    public function requirementsForBatch(int $batchId): array
    {
        $s=$this->db->prepare('SELECT * FROM batch_recipe_requirements WHERE batch_id=? ORDER BY ingredient_name,quantity_unit');$s->execute([$batchId]);return $s->fetchAll();
    }

    public function coverage(int $batchId): array
    {
        $requirements=$this->requirementsForBatch($batchId);
        if(!$requirements)return ['controlled'=>false,'complete'=>true,'requirements'=>[],'missing'=>[],'excess'=>[]];

        $s=$this->db->prepare("SELECT lower(l.ingredient_name) ingredient_key,l.ingredient_name,pbi.quantity_unit,COALESCE(SUM(pbi.quantity_used),0) actual
            FROM production_batch_ingredients pbi JOIN ingredient_lots l ON l.id=pbi.ingredient_lot_id
            WHERE pbi.batch_id=? GROUP BY lower(l.ingredient_name),pbi.quantity_unit");
        $s->execute([$batchId]);$actual=[];
        foreach($s->fetchAll() as $row)$actual[$this->key((string)$row['ingredient_name'],(string)$row['quantity_unit'])]=(float)$row['actual'];

        $missing=[];$excess=[];$detail=[];
        foreach($requirements as $r){
            $key=$this->key((string)$r['ingredient_name'],(string)$r['quantity_unit']);
            $expected=(float)$r['expected_quantity'];$used=(float)($actual[$key]??0.0);$delta=round($used-$expected,6);
            $entry=['ingredient_name'=>$r['ingredient_name'],'quantity_unit'=>$r['quantity_unit'],'expected'=>$expected,'actual'=>$used,'delta'=>$delta,'recipe_version'=>(int)$r['recipe_version']];
            $detail[]=$entry;
            if($used+0.000001<$expected)$missing[]=$entry;
            elseif($used>$expected+0.000001)$excess[]=$entry;
            unset($actual[$key]);
        }
        foreach($actual as $key=>$used)if($used>0)$excess[]=['ingredient_name'=>$key,'quantity_unit'=>'','expected'=>0.0,'actual'=>$used,'delta'=>$used,'recipe_version'=>null];
        return ['controlled'=>true,'complete'=>!$missing,'requirements'=>$detail,'missing'=>$missing,'excess'=>$excess];
    }

    public function autoAllocateBatch(int $batchId,int $adminId): array
    {
        $batch=$this->batch($batchId);
        if(in_array($batch['status'],['recalled','depleted'],true)) throw new \InvalidArgumentException('Ingredient allocation requires an active or held production batch.');
        if(!$this->snapshotBatch($batchId)) throw new \InvalidArgumentException('This flavor has no active recipe to allocate.');
        $coverage=$this->coverage($batchId);if($coverage['complete'])return ['allocated'=>0,'coverage'=>$coverage];

        $plan=[];
        foreach($coverage['missing'] as $need){
            $remaining=round((float)$need['expected']-(float)$need['actual'],6);
            $lots=$this->availableLots((string)$need['ingredient_name'],(string)$need['quantity_unit'],(string)$batch['produced_at'],$batchId);
            foreach($lots as $lot){
                if($remaining<=0.000001)break;
                $available=(float)$lot['available_quantity'];
                if($available<=0)continue;
                $take=min($remaining,$available);
                $target=round((float)$lot['current_batch_used']+$take,6);
                $plan[]=['lot_id'=>(int)$lot['id'],'quantity'=>$target,'increment'=>$take,'unit'=>$need['quantity_unit']];
                $remaining=round($remaining-$take,6);
            }
            if($remaining>0.000001) throw new \InvalidArgumentException('Insufficient active supplier lot quantity for '.$need['ingredient_name'].' (need '.rtrim(rtrim(number_format($remaining,6,'.',''),'0'),'.').' '.$need['quantity_unit'].' more).');
        }

        $trace=new IngredientTraceabilityService($this->db);$this->db->beginTransaction();
        try{
            foreach($plan as $row)$trace->linkBatch($batchId,$row['lot_id'],$row['quantity'],$row['unit'],$adminId);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        return ['allocated'=>count($plan),'coverage'=>$this->coverage($batchId)];
    }

    public function summary(): array
    {
        $activeFlavors=(int)$this->db->query('SELECT COUNT(*) FROM flavors WHERE active=1')->fetchColumn();
        $withRecipe=(int)$this->db->query("SELECT COUNT(DISTINCT flavor_id) FROM flavor_recipes WHERE status='active'")->fetchColumn();
        $controlledBatches=(int)$this->db->query('SELECT COUNT(DISTINCT batch_id) FROM batch_recipe_requirements')->fetchColumn();
        return ['active_flavors'=>$activeFlavors,'flavors_with_recipe'=>$withRecipe,'missing_recipes'=>max(0,$activeFlavors-$withRecipe),'controlled_batches'=>$controlledBatches];
    }

    private function availableLots(string $ingredient,string $unit,string $producedAt,int $batchId): array
    {
        $s=$this->db->prepare("SELECT l.*,
            COALESCE((SELECT SUM(pc.quantity_used) FROM production_batch_ingredients pc WHERE pc.ingredient_lot_id=l.id AND pc.batch_id=?),0) current_batch_used,
            CASE WHEN l.quantity_received IS NULL THEN 0 ELSE l.quantity_received-COALESCE((SELECT SUM(p.quantity_used) FROM production_batch_ingredients p WHERE p.ingredient_lot_id=l.id AND p.batch_id<>?),0)-COALESCE((SELECT SUM(pc.quantity_used) FROM production_batch_ingredients pc WHERE pc.ingredient_lot_id=l.id AND pc.batch_id=?),0) END available_quantity
            FROM ingredient_lots l
            WHERE lower(l.ingredient_name)=lower(?) AND l.quantity_unit=? AND l.status='active' AND l.quantity_received IS NOT NULL
              AND l.received_at<=? AND (l.best_by_date IS NULL OR l.best_by_date>=date(?))
            ORDER BY CASE WHEN l.best_by_date IS NULL THEN 1 ELSE 0 END,l.best_by_date,l.received_at,l.id");
        $s->execute([$batchId,$batchId,$batchId,$ingredient,$unit,$producedAt,$producedAt]);return $s->fetchAll();
    }

    private function normalizeComponents(array $components): array
    {
        $out=[];$seen=[];
        foreach($components as $c){
            $name=mb_substr(trim((string)($c['ingredient_name']??'')),0,190);$unit=mb_substr(trim((string)($c['quantity_unit']??'')),0,32);
            $qty=(float)($c['quantity_per_donut']??0);
            if($name===''&&$qty<=0&&$unit==='')continue;
            if($name===''||$unit===''||$qty<=0)throw new \InvalidArgumentException('Every recipe ingredient needs a name, positive quantity per donut, and unit.');
            $key=$this->key($name,$unit);if(isset($seen[$key]))throw new \InvalidArgumentException('Duplicate recipe ingredient and unit: '.$name.'.');
            $seen[$key]=true;$out[]=['ingredient_name'=>$name,'quantity_per_donut'=>round($qty,6),'quantity_unit'=>$unit];
        }
        return $out;
    }

    private function activateWithinTransaction(int $recipeId,int $flavorId): void
    {
        $r=$this->db->prepare("UPDATE flavor_recipes SET status='retired' WHERE flavor_id=? AND status='active' AND id<>?");$r->execute([$flavorId,$recipeId]);
        $s=$this->db->prepare("UPDATE flavor_recipes SET status='active',activated_at=CURRENT_TIMESTAMP WHERE id=? AND flavor_id=?");$s->execute([$recipeId,$flavorId]);
        if($s->rowCount()!==1)throw new \InvalidArgumentException('Recipe not found.');
    }

    private function recipeUsedByBatch(int $recipeId): bool
    {
        $s=$this->db->prepare('SELECT COUNT(*) FROM batch_recipe_requirements WHERE recipe_id=?');$s->execute([$recipeId]);return (int)$s->fetchColumn()>0;
    }

    private function flavorExists(int $id): bool
    {
        $s=$this->db->prepare('SELECT 1 FROM flavors WHERE id=?');$s->execute([$id]);return (bool)$s->fetchColumn();
    }

    private function batch(int $id): array
    {
        $s=$this->db->prepare('SELECT * FROM production_batches WHERE id=?');$s->execute([$id]);$row=$s->fetch();
        if(!$row)throw new \InvalidArgumentException('Production batch not found.');return $row;
    }

    private function key(string $ingredient,string $unit): string
    {
        return mb_strtolower(trim($ingredient)).'|'.mb_strtolower(trim($unit));
    }
}
