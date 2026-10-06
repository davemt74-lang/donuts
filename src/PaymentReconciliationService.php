<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class PaymentReconciliationService
{
    public function __construct(private readonly PDO $db) {}

    public function reconcile(int $orderId,string $stripeSessionId,array $stripeSession): array
    {
        $s=$this->db->prepare('SELECT total_cents,currency,status FROM orders WHERE id=?');
        $s->execute([$orderId]);$order=$s->fetch();
        if(!$order) throw new \InvalidArgumentException('Order not found.');

        $expected=(int)$order['total_cents'];
        $subtotal=(int)($stripeSession['amount_subtotal']??-1);
        $total=(int)($stripeSession['amount_total']??-1);
        $tax=(int)($stripeSession['total_details']['amount_tax']??0);
        $currency=strtolower((string)($stripeSession['currency']??''));
        $expectedCurrency=strtolower((string)$order['currency']);

        $reasons=[];
        if($subtotal!==$expected)$reasons[]='pre_tax_total_mismatch';
        if($total!==$subtotal+$tax)$reasons[]='stripe_total_math_mismatch';
        if($currency!==$expectedCurrency)$reasons[]='currency_mismatch';
        if($subtotal<0 || $total<0 || $tax<0)$reasons[]='invalid_negative_amount';
        $status=$reasons?'mismatch':'matched';

        $u=$this->db->prepare('INSERT INTO order_payment_reconciliation(order_id,stripe_session_id,expected_pre_tax_cents,stripe_subtotal_cents,stripe_tax_cents,stripe_total_cents,currency,status,details) VALUES(?,?,?,?,?,?,?,?,?) ON CONFLICT(order_id) DO UPDATE SET stripe_session_id=excluded.stripe_session_id,expected_pre_tax_cents=excluded.expected_pre_tax_cents,stripe_subtotal_cents=excluded.stripe_subtotal_cents,stripe_tax_cents=excluded.stripe_tax_cents,stripe_total_cents=excluded.stripe_total_cents,currency=excluded.currency,status=excluded.status,details=excluded.details,reconciled_at=CURRENT_TIMESTAMP');
        $u->execute([$orderId,$stripeSessionId,$expected,$subtotal,$tax,$total,$currency,$status,json_encode($reasons,JSON_THROW_ON_ERROR)]);

        return ['matched'=>$status==='matched','status'=>$status,'reasons'=>$reasons,'expected_pre_tax_cents'=>$expected,'stripe_subtotal_cents'=>$subtotal,'stripe_tax_cents'=>$tax,'stripe_total_cents'=>$total,'currency'=>$currency];
    }

    public function forOrder(int $orderId): ?array
    {
        $s=$this->db->prepare('SELECT * FROM order_payment_reconciliation WHERE order_id=?');$s->execute([$orderId]);return $s->fetch()?:null;
    }
}
