<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class FulfillmentService
{
    public function __construct(private readonly PDO $db) {}

    public function details(int $orderId): array
    {
        $s=$this->db->prepare('SELECT * FROM order_fulfillment_details WHERE order_id=?');$s->execute([$orderId]);$row=$s->fetch();
        return $row?:[
            'order_id'=>$orderId,'carrier'=>'','tracking_number'=>'','tracking_url'=>'','pickup_instructions'=>'',
            'pickup_ready_at'=>null,'shipped_at'=>null,'delivered_at'=>null,'updated_at'=>null
        ];
    }

    public function save(int $orderId,array $data): array
    {
        $carrier=mb_substr(trim((string)($data['carrier']??'')),0,80);
        $tracking=mb_substr(trim((string)($data['tracking_number']??'')),0,190);
        $url=trim((string)($data['tracking_url']??''));
        if($url!=='' && !filter_var($url,FILTER_VALIDATE_URL)) throw new \InvalidArgumentException('Tracking URL must be valid.');
        $pickup=mb_substr(trim((string)($data['pickup_instructions']??'')),0,2000);
        $ready=trim((string)($data['pickup_ready_at']??''))?:null;
        $s=$this->db->prepare('INSERT INTO order_fulfillment_details(order_id,carrier,tracking_number,tracking_url,pickup_instructions,pickup_ready_at) VALUES(?,?,?,?,?,?) ON CONFLICT(order_id) DO UPDATE SET carrier=excluded.carrier,tracking_number=excluded.tracking_number,tracking_url=excluded.tracking_url,pickup_instructions=excluded.pickup_instructions,pickup_ready_at=excluded.pickup_ready_at,updated_at=CURRENT_TIMESTAMP');
        $s->execute([$orderId,$carrier,$tracking,$url,$pickup,$ready]);
        return $this->details($orderId);
    }

    public function markShipped(int $orderId): void
    {
        $s=$this->db->prepare('INSERT INTO order_fulfillment_details(order_id,shipped_at) VALUES(?,CURRENT_TIMESTAMP) ON CONFLICT(order_id) DO UPDATE SET shipped_at=COALESCE(order_fulfillment_details.shipped_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP');
        $s->execute([$orderId]);
    }

    public function markDelivered(int $orderId): void
    {
        $s=$this->db->prepare('INSERT INTO order_fulfillment_details(order_id,delivered_at) VALUES(?,CURRENT_TIMESTAMP) ON CONFLICT(order_id) DO UPDATE SET delivered_at=COALESCE(order_fulfillment_details.delivered_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP');
        $s->execute([$orderId]);
    }
}
