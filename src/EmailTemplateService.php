<?php
declare(strict_types=1);

namespace FudgeDonuts;

final class EmailTemplateService
{
    public function orderConfirmation(array $order): array
    {
        $subject='Your Fudge Donuts order '.$order['order_number'];
        $text="Thanks for your order, {$order['first_name']}.\n\nOrder: {$order['order_number']}\nTotal: ".\money((int)$order['total_cents'])."\nFulfillment: {$order['fulfillment_name']}\n\nWe’ll let you know when it moves to the next step.";
        return [$subject,$text,$this->wrap('Order confirmed',[
            'Thanks for your order, '.htmlspecialchars((string)$order['first_name']).'.',
            '<strong>Order:</strong> '.htmlspecialchars((string)$order['order_number']),
            '<strong>Total:</strong> '.\money((int)$order['total_cents']),
            '<strong>Fulfillment:</strong> '.htmlspecialchars((string)$order['fulfillment_name']),
            'We’ll let you know when it moves to the next step.',
        ])];
    }

    public function statusUpdate(array $order,string $status): array
    {
        $label=ucwords(str_replace('_',' ',$status));
        $subject='Order '.$order['order_number'].': '.$label;
        $text="Your Fudge Donuts order {$order['order_number']} is now {$label}.";
        return [$subject,$text,$this->wrap('Order update',[
            'Your Fudge Donuts order <strong>'.htmlspecialchars((string)$order['order_number']).'</strong> is now <strong>'.htmlspecialchars($label).'</strong>.'
        ])];
    }

    public function fulfillmentUpdate(array $order,array $details): array
    {
        $status=ucwords(str_replace('_',' ',(string)$order['status']));
        $subject='Order '.$order['order_number'].': '.$status;
        $text=["Your Fudge Donuts order {$order['order_number']} is now {$status}."];
        $html=['Your order <strong>'.htmlspecialchars((string)$order['order_number']).'</strong> is now <strong>'.htmlspecialchars($status).'</strong>.'];
        if(!empty($details['carrier'])){$text[]='Carrier: '.$details['carrier'];$html[]='<strong>Carrier:</strong> '.htmlspecialchars((string)$details['carrier']);}
        if(!empty($details['tracking_number'])){$text[]='Tracking: '.$details['tracking_number'];$html[]='<strong>Tracking:</strong> '.htmlspecialchars((string)$details['tracking_number']);}
        if(!empty($details['tracking_url'])){$text[]='Track your order: '.$details['tracking_url'];$html[]='<a href="'.htmlspecialchars((string)$details['tracking_url']).'">Track your order</a>';}
        if(!empty($details['pickup_instructions'])){$text[]='Pickup instructions: '.$details['pickup_instructions'];$html[]='<strong>Pickup instructions:</strong><br>'.nl2br(htmlspecialchars((string)$details['pickup_instructions']));}
        if(!empty($details['pickup_ready_at'])){$text[]='Pickup ready: '.$details['pickup_ready_at'];$html[]='<strong>Pickup ready:</strong> '.htmlspecialchars((string)$details['pickup_ready_at']);}
        return [$subject,implode("\n\n",$text),$this->wrap('Order update',$html)];
    }

    public function passwordReset(string $firstName,string $url,string $expiresAt): array
    {
        $name=trim($firstName)!==''?$firstName:'there';
        $subject='Reset your Fudge Donuts password';
        $text="Hi {$name},\n\nUse this secure link to reset your Fudge Donuts password:\n{$url}\n\nThis link expires at {$expiresAt} UTC. If you did not request a reset, you can ignore this email.";
        $html=$this->wrap('Reset your password',[
            'Hi '.htmlspecialchars($name).',',
            'Use the button below to reset your Fudge Donuts password.',
            '<a class="button" href="'.htmlspecialchars($url).'">Reset password</a>',
            'This link expires at '.htmlspecialchars($expiresAt).' UTC. If you did not request a reset, you can ignore this email.',
        ]);
        return [$subject,$text,$html];
    }

    private function wrap(string $title,array $parts): string
    {
        $body='';foreach($parts as $part)$body.='<p>'.$part.'</p>';
        return '<!doctype html><html><body style="margin:0;background:#f7efe5;font-family:Arial,sans-serif;color:#23150f"><div style="max-width:620px;margin:auto;padding:32px 20px"><div style="background:#fff;border-radius:20px;padding:32px"><div style="font-family:Georgia,serif;font-size:24px;font-weight:bold;margin-bottom:24px">Fudge Donuts</div><h1 style="font-family:Georgia,serif;font-size:32px;margin:0 0 20px">'.htmlspecialchars($title).'</h1>'.$body.'<p style="margin-top:28px;font-size:13px;color:#78675c">Fudge Donuts · This is a transactional message about your account or order.</p></div></div><style>.button{display:inline-block;background:#23150f;color:#fff!important;text-decoration:none;padding:12px 18px;border-radius:999px;font-weight:bold}</style></body></html>';
    }
}
