<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class DiscountService
{
    public function __construct(private readonly PDO $db) {}

    public function calculate(int $subtotalCents, int $units, ?string $code = null): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $sql = "SELECT * FROM discount_rules WHERE active=1
          AND (starts_at IS NULL OR starts_at <= ?)
          AND (ends_at IS NULL OR ends_at >= ?)
          AND (usage_limit IS NULL OR usage_count < usage_limit)
          AND min_units <= ? AND min_subtotal_cents <= ?
          AND ((code IS NULL AND ? IS NULL) OR (code = ?))
          ORDER BY sort_order, id";
        $s=$this->db->prepare($sql);
        $normalized=$code!==null?strtoupper(trim($code)):null;
        $s->execute([$now,$now,$units,$subtotalCents,$normalized,$normalized]);
        $rules=$s->fetchAll();

        $discount=0;
        $applied=[];
        foreach($rules as $rule){
            $amount=$rule['type']==='percent'
                ? intdiv($subtotalCents*(int)$rule['value'],100)
                : min($subtotalCents,(int)$rule['value']);
            if($amount<=0) continue;
            $discount += $amount;
            $applied[]=['id'=>(int)$rule['id'],'name'=>$rule['name'],'amount_cents'=>$amount];
        }
        $discount=min($subtotalCents,$discount);
        return ['discount_cents'=>$discount,'applied'=>$applied];
    }
}
