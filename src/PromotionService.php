<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class PromotionService
{
    public function __construct(private readonly PDO $db) {}

    public function save(array $data): int
    {
        $id=(int)($data['id']??0);
        $name=trim((string)($data['name']??''));
        $type=(string)($data['type']??'percent');
        $value=(int)($data['value']??0);
        if($name==='' || !in_array($type,['percent','fixed'],true) || $value<=0) throw new \InvalidArgumentException('Enter a valid promotion.');
        if($type==='percent' && $value>100) throw new \InvalidArgumentException('Percent discount cannot exceed 100%.');
        $code=strtoupper(trim((string)($data['code']??'')))?:null;
        $vals=[$code,$name,$type,$value,max(0,(int)($data['min_units']??0)),max(0,(int)($data['min_subtotal_cents']??0)),!empty($data['active'])?1:0,trim((string)($data['starts_at']??''))?:null,trim((string)($data['ends_at']??''))?:null,($data['usage_limit']??'')===''?null:max(1,(int)$data['usage_limit']),(int)($data['sort_order']??0)];
        if($id){
            $vals[]=$id;$s=$this->db->prepare('UPDATE discount_rules SET code=?,name=?,type=?,value=?,min_units=?,min_subtotal_cents=?,active=?,starts_at=?,ends_at=?,usage_limit=?,sort_order=? WHERE id=?');$s->execute($vals);return $id;
        }
        $s=$this->db->prepare('INSERT INTO discount_rules(code,name,type,value,min_units,min_subtotal_cents,active,starts_at,ends_at,usage_limit,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$s->execute($vals);return (int)$this->db->lastInsertId();
    }

    public function recordOrderDiscounts(int $orderId,array $discounts): void
    {
        $s=$this->db->prepare('INSERT OR IGNORE INTO order_discounts(order_id,discount_rule_id,name,amount_cents) VALUES(?,?,?,?)');
        foreach($discounts as $d)$s->execute([$orderId,(int)$d['id'],(string)$d['name'],(int)$d['amount_cents']]);
    }

    public function redeemOrder(int $orderId): void
    {
        $s=$this->db->prepare('SELECT discount_rule_id FROM order_discounts WHERE order_id=?');$s->execute([$orderId]);
        foreach($s->fetchAll() as $row){
            $ruleId=(int)$row['discount_rule_id'];
            $i=$this->db->prepare('INSERT OR IGNORE INTO discount_redemptions(order_id,discount_rule_id) VALUES(?,?)');$i->execute([$orderId,$ruleId]);
            if($i->rowCount()===1){$u=$this->db->prepare('UPDATE discount_rules SET usage_count=usage_count+1 WHERE id=?');$u->execute([$ruleId]);}
        }
    }
}
