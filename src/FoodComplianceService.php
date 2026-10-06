<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class FoodComplianceService
{
    public function __construct(private readonly PDO $db) {}

    public function profile(int $flavorId): array
    {
        $s=$this->db->prepare('SELECT f.id,f.name,f.slug,f.active,f.image_path,c.* FROM flavors f LEFT JOIN flavor_compliance_profiles c ON c.flavor_id=f.id WHERE f.id=?');
        $s->execute([$flavorId]);$row=$s->fetch();
        if(!$row) throw new \InvalidArgumentException('Flavor not found.');
        return $this->normalize($row);
    }

    public function publicProfile(int $flavorId): ?array
    {
        $p=$this->profile($flavorId);
        return !empty($p['published']) && $p['completeness']['complete'] ? $p : null;
    }

    public function all(): array
    {
        $rows=$this->db->query('SELECT f.id,f.name,f.slug,f.active,f.image_path,c.* FROM flavors f LEFT JOIN flavor_compliance_profiles c ON c.flavor_id=f.id ORDER BY f.sort_order,f.name')->fetchAll();
        return array_map(fn($row)=>$this->normalize($row),$rows);
    }

    public function save(int $flavorId,array $data,int $adminId): array
    {
        $current=$this->profile($flavorId);
        $ingredients=mb_substr(trim((string)($data['ingredient_statement']??'')),0,10000);
        $allergens=mb_substr(trim((string)($data['allergen_statement']??'')),0,4000);
        $shared=mb_substr(trim((string)($data['shared_kitchen_notice']??'')),0,2000);
        $storage=mb_substr(trim((string)($data['storage_instructions']??'')),0,2000);
        $shelf=trim((string)($data['shelf_life_days']??''));$shelf=$shelf===''?null:(int)$shelf;
        $weight=trim((string)($data['net_weight_oz']??''));$weight=$weight===''?null:(float)$weight;
        $version=mb_substr(trim((string)($data['label_version']??'1')),0,40);
        $publish=!empty($data['published']);

        if($shelf!==null && ($shelf<1 || $shelf>365)) throw new \InvalidArgumentException('Shelf life must be between 1 and 365 days.');
        if($weight!==null && $weight<=0) throw new \InvalidArgumentException('Net weight must be greater than zero.');
        if($version==='') throw new \InvalidArgumentException('Label version is required.');

        $candidate=[
            'ingredient_statement'=>$ingredients,'allergen_statement'=>$allergens,'shared_kitchen_notice'=>$shared,
            'storage_instructions'=>$storage,'shelf_life_days'=>$shelf,'net_weight_oz'=>$weight,'label_version'=>$version
        ];
        $complete=$this->completeness($candidate);
        if($publish && !$complete['complete']) throw new \InvalidArgumentException('Complete all required compliance fields before publishing.');

        $s=$this->db->prepare("INSERT INTO flavor_compliance_profiles(flavor_id,ingredient_statement,allergen_statement,shared_kitchen_notice,storage_instructions,shelf_life_days,net_weight_oz,label_version,published,published_at)
            VALUES(?,?,?,?,?,?,?,?,?,CASE WHEN ?=1 THEN CURRENT_TIMESTAMP ELSE NULL END)
            ON CONFLICT(flavor_id) DO UPDATE SET ingredient_statement=excluded.ingredient_statement,allergen_statement=excluded.allergen_statement,shared_kitchen_notice=excluded.shared_kitchen_notice,storage_instructions=excluded.storage_instructions,shelf_life_days=excluded.shelf_life_days,net_weight_oz=excluded.net_weight_oz,label_version=excluded.label_version,published=excluded.published,published_at=CASE WHEN excluded.published=1 THEN COALESCE(flavor_compliance_profiles.published_at,CURRENT_TIMESTAMP) ELSE NULL END,updated_at=CURRENT_TIMESTAMP");
        $s->execute([$flavorId,$ingredients,$allergens,$shared,$storage,$shelf,$weight,$version,$publish?1:0,$publish?1:0]);

        $updated=$this->profile($flavorId);
        try{
            (new AdminAuditService($this->db))->record($adminId,'food_compliance_updated','flavor',$flavorId,'Food compliance profile updated.',$current,$updated);
        }catch(\Throwable){}
        return $updated;
    }

    public function summary(): array
    {
        $rows=$this->all();$active=array_values(array_filter($rows,fn($r)=>(int)$r['active']===1));
        $complete=count(array_filter($active,fn($r)=>$r['completeness']['complete']));
        $published=count(array_filter($active,fn($r)=>(int)$r['published']===1 && $r['completeness']['complete']));
        return ['active'=>count($active),'complete'=>$complete,'published'=>$published,'incomplete'=>count($active)-$complete];
    }

    public function completeness(array $data): array
    {
        $required=[
            'ingredient_statement'=>'Ingredients',
            'allergen_statement'=>'Allergen statement',
            'storage_instructions'=>'Storage instructions',
            'shelf_life_days'=>'Shelf life',
            'net_weight_oz'=>'Net weight',
        ];
        $missing=[];
        foreach($required as $key=>$label){
            $value=$data[$key]??null;
            if($value===null || (is_string($value)&&trim($value)==='') || (is_numeric($value)&&(float)$value<=0))$missing[]=$label;
        }
        $score=(int)round((count($required)-count($missing))/count($required)*100);
        return ['score'=>$score,'missing'=>$missing,'complete'=>count($missing)===0];
    }

    private function normalize(array $row): array
    {
        $defaults=[
            'ingredient_statement'=>'','allergen_statement'=>'','shared_kitchen_notice'=>'Prepared in a shared kitchen. Cross-contact may occur.',
            'storage_instructions'=>'','shelf_life_days'=>null,'net_weight_oz'=>null,'label_version'=>'1','published'=>0,'published_at'=>null,'updated_at'=>null
        ];
        $row=array_merge($defaults,$row);
        $row['completeness']=$this->completeness($row);
        return $row;
    }
}
