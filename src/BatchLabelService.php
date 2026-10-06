<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class BatchLabelService
{
    public function __construct(private readonly PDO $db) {}

    public function enabled(): bool
    {
        try{
            $s=$this->db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name='production_batch_compliance_snapshots'");
            $s->execute();return (bool)$s->fetchColumn();
        }catch(\Throwable){return false;}
    }

    public function prepare(int $flavorId,string $producedAt,?string $requestedBestBy=null): array
    {
        $producedTs=strtotime($producedAt);
        if($producedTs===false) throw new \InvalidArgumentException('Production date/time is invalid.');
        $producedDate=gmdate('Y-m-d',$producedTs);

        $profile=(new FoodComplianceService($this->db))->publicProfile($flavorId);
        if(!$profile) throw new \InvalidArgumentException('Publish a complete food-compliance profile before producing this flavor.');

        $shelf=(int)$profile['shelf_life_days'];
        $derived=(new \DateTimeImmutable($producedDate,new \DateTimeZone('UTC')))->modify('+'.$shelf.' days')->format('Y-m-d');
        $requested=trim((string)$requestedBestBy);
        if($requested!==''){
            $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$requested,new \DateTimeZone('UTC'));
            if(!$d || $d->format('Y-m-d')!==$requested) throw new \InvalidArgumentException('Best-by date is invalid.');
            if($requested<$producedDate) throw new \InvalidArgumentException('Best-by date cannot be before production date.');
            if($requested>$derived) throw new \InvalidArgumentException('Best-by date cannot exceed the published shelf-life limit.');
            $bestBy=$requested;
        }else{
            $bestBy=$derived;
        }

        return [
            'flavor_id'=>$flavorId,'label_version'=>(string)$profile['label_version'],
            'ingredient_statement'=>(string)$profile['ingredient_statement'],'allergen_statement'=>(string)$profile['allergen_statement'],
            'shared_kitchen_notice'=>(string)$profile['shared_kitchen_notice'],'storage_instructions'=>(string)$profile['storage_instructions'],
            'shelf_life_days'=>$shelf,'net_weight_oz'=>(float)$profile['net_weight_oz'],
            'produced_date'=>$producedDate,'best_by_date'=>$bestBy,
        ];
    }

    public function snapshot(int $batchId,array $label): void
    {
        $s=$this->db->prepare('INSERT INTO production_batch_compliance_snapshots(batch_id,flavor_id,label_version,ingredient_statement,allergen_statement,shared_kitchen_notice,storage_instructions,shelf_life_days,net_weight_oz,produced_date,best_by_date) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $s->execute([
            $batchId,(int)$label['flavor_id'],(string)$label['label_version'],(string)$label['ingredient_statement'],
            (string)$label['allergen_statement'],(string)$label['shared_kitchen_notice'],(string)$label['storage_instructions'],
            (int)$label['shelf_life_days'],(float)$label['net_weight_oz'],(string)$label['produced_date'],(string)$label['best_by_date']
        ]);
    }

    public function forBatch(int $batchId): array
    {
        $s=$this->db->prepare("SELECT b.batch_code,b.status,b.quantity_produced,b.quantity_remaining,f.name flavor_name,s.*
            FROM production_batches b JOIN flavors f ON f.id=b.flavor_id
            JOIN production_batch_compliance_snapshots s ON s.batch_id=b.id WHERE b.id=?");
        $s->execute([$batchId]);$row=$s->fetch();
        if(!$row) throw new \InvalidArgumentException('Batch compliance label is unavailable.');
        return $row;
    }

    public function missingSnapshots(): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM production_batches b LEFT JOIN production_batch_compliance_snapshots s ON s.batch_id=b.id WHERE s.batch_id IS NULL AND b.status IN ('active','hold')")->fetchColumn();
    }
}
