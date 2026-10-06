<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class GiftService
{
    public function __construct(private readonly PDO $db) {}

    public function normalizeCheckout(array $data): array
    {
        if(empty($data['is_gift'])) return [
            'hide_price'=>false,'gift_packaging'=>'standard',
            'gift_delivery_date'=>'','gift_recipient_email'=>''
        ];
        $packaging=(string)($data['gift_packaging']??'standard');
        if(!in_array($packaging,['standard','gift-box'],true)) throw new \InvalidArgumentException('Invalid gift packaging.');
        $date=trim((string)($data['gift_delivery_date']??''));
        if($date!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || $date<gmdate('Y-m-d'))) {
            throw new \InvalidArgumentException('Gift delivery date must be today or later.');
        }
        $recipientEmail=strtolower(trim((string)($data['gift_recipient_email']??'')));
        if($recipientEmail!=='' && !filter_var($recipientEmail,FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Enter a valid recipient email.');
        return [
            'hide_price'=>!empty($data['hide_price']),
            'gift_packaging'=>$packaging,
            'gift_delivery_date'=>$date,
            'gift_recipient_email'=>$recipientEmail,
        ];
    }

    public function persist(int $orderId,array $checkout): void
    {
        $s=$this->db->prepare('INSERT OR REPLACE INTO order_gift_options(order_id,hide_price,packaging,requested_delivery_date,recipient_email) VALUES(?,?,?,?,?)');
        $s->execute([
            $orderId,!empty($checkout['hide_price'])?1:0,
            (string)($checkout['gift_packaging']??'standard'),
            ($checkout['gift_delivery_date']??'')!==''?$checkout['gift_delivery_date']:null,
            (string)($checkout['gift_recipient_email']??'')
        ]);
    }

    public function forOrder(int $orderId): ?array
    {
        $s=$this->db->prepare('SELECT * FROM order_gift_options WHERE order_id=?');$s->execute([$orderId]);return $s->fetch()?:null;
    }
}
