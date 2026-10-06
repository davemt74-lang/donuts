<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class TaxService
{
    public function __construct(private readonly PDO $db) {}

    public function settings(): array
    {
        $defaults=[
            'automatic_tax_enabled'=>'1',
            'product_tax_code'=>'',
            'checkout_notice'=>'Tax is calculated securely at checkout based on your delivery address.',
            'tax_behavior'=>'exclusive',
        ];
        try{
            foreach($this->db->query('SELECT setting_key,setting_value FROM tax_settings')->fetchAll() as $row){
                $defaults[(string)$row['setting_key']]=(string)$row['setting_value'];
            }
        }catch(\Throwable){}
        return $defaults;
    }

    public function save(array $data): void
    {
        $enabled=!empty($data['automatic_tax_enabled'])?'1':'0';
        $taxCode=trim((string)($data['product_tax_code']??''));
        if($taxCode!=='' && !preg_match('/^txcd_\d+$/',$taxCode)) throw new \InvalidArgumentException('Stripe tax code must look like txcd_########.');
        $behavior=(string)($data['tax_behavior']??'exclusive');
        if($behavior!=='exclusive') throw new \InvalidArgumentException('Only exclusive tax is supported by the current reconciliation model.');
        $notice=mb_substr(trim((string)($data['checkout_notice']??'')),0,1000);

        $values=[
            'automatic_tax_enabled'=>$enabled,
            'product_tax_code'=>$taxCode,
            'checkout_notice'=>$notice,
            'tax_behavior'=>$behavior,
        ];
        $s=$this->db->prepare('INSERT INTO tax_settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=CURRENT_TIMESTAMP');
        foreach($values as $key=>$value)$s->execute([$key,$value]);
    }

    public function checkoutParams(): array
    {
        $s=$this->settings();$params=[];
        $enabled=$s['automatic_tax_enabled']==='1';
        $params['automatic_tax[enabled]']=$enabled?'true':'false';
        $params['line_items[0][price_data][tax_behavior]']='exclusive';
        if($enabled && $s['product_tax_code']!==''){
            $params['line_items[0][price_data][product_data][tax_code]']=$s['product_tax_code'];
        }
        return $params;
    }

    public function calculateExclusiveForOrder(StripeService $stripe,array $order): int
    {
        $settings=$this->settings();
        if($settings['automatic_tax_enabled']!=='1') return 0;
        $calculation=$stripe->calculateTax(
            (int)$order['total_cents'],
            [
                'line1'=>$order['line1']??'','line2'=>$order['line2']??'','city'=>$order['city']??'',
                'region'=>$order['region']??'','postal_code'=>$order['postal_code']??'','country'=>$order['country']??'US',
            ],
            (string)$order['order_number'],
            (string)$settings['product_tax_code']
        );
        $tax=array_key_exists('tax_amount_exclusive',$calculation)
            ?(int)$calculation['tax_amount_exclusive']
            :max(0,(int)($calculation['amount_total']??0)-(int)$order['total_cents']);
        if($tax<0) throw new \RuntimeException('Stripe returned an invalid tax amount.');
        return $tax;
    }

    public function snapshotOrder(int $orderId): void
    {
        $s=$this->settings();
        $q=$this->db->prepare('INSERT INTO order_tax_details(order_id,automatic_tax_enabled,product_tax_code,tax_behavior) VALUES(?,?,?,?) ON CONFLICT(order_id) DO UPDATE SET automatic_tax_enabled=excluded.automatic_tax_enabled,product_tax_code=excluded.product_tax_code,tax_behavior=excluded.tax_behavior,updated_at=CURRENT_TIMESTAMP');
        $q->execute([$orderId,$s['automatic_tax_enabled']==='1'?1:0,$s['product_tax_code'],$s['tax_behavior']]);
    }

    public function recordCalculated(int $orderId,int $taxCents): void
    {
        if($taxCents<0) throw new \InvalidArgumentException('Tax amount cannot be negative.');
        $q=$this->db->prepare('UPDATE order_tax_details SET calculated_tax_cents=?,updated_at=CURRENT_TIMESTAMP WHERE order_id=?');
        $q->execute([$taxCents,$orderId]);
    }

    public function recordCollected(int $orderId,int $taxCents): void
    {
        if($taxCents<0) throw new \InvalidArgumentException('Tax amount cannot be negative.');
        $q=$this->db->prepare('UPDATE order_tax_details SET stripe_tax_cents=?,updated_at=CURRENT_TIMESTAMP WHERE order_id=?');
        $q->execute([$taxCents,$orderId]);
    }

    public function calculatedForOrder(int $orderId): int
    {
        try{$s=$this->db->prepare('SELECT calculated_tax_cents FROM order_tax_details WHERE order_id=?');$s->execute([$orderId]);return max(0,(int)($s->fetchColumn()?:0));}
        catch(\Throwable){return 0;}
    }

    public function orderDetail(int $orderId): ?array
    {
        try{$s=$this->db->prepare('SELECT * FROM order_tax_details WHERE order_id=?');$s->execute([$orderId]);return $s->fetch()?:null;}
        catch(\Throwable){return null;}
    }

    public function byRegion(?string $start=null,?string $end=null): array
    {
        $where="o.status NOT IN ('cancelled','payment_failed')";$params=[];
        if($start){$where.=' AND o.created_at>=?';$params[]=$start.' 00:00:00';}
        if($end){$where.=' AND o.created_at<=?';$params[]=$end.' 23:59:59';}
        $s=$this->db->prepare("SELECT o.region,COUNT(*) orders,COALESCE(SUM(o.total_cents),0) gross_cents,COALESCE(SUM(o.tax_cents),0) tax_cents FROM orders o WHERE {$where} GROUP BY o.region ORDER BY tax_cents DESC,gross_cents DESC");
        $s->execute($params);return $s->fetchAll();
    }
}
