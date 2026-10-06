<?php
declare(strict_types=1);

namespace FudgeDonuts;

final class CheckoutService
{
    public function validate(array $data): array
    {
        $email=strtolower(trim((string)($data['email']??'')));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Enter a valid email address.');
        $required=['first_name','last_name','line1','city','region','postal_code'];
        foreach($required as $field) if(trim((string)($data[$field]??''))==='') throw new \InvalidArgumentException('Complete all required checkout fields.');
        $giftMessage=trim((string)($data['gift_message']??''));
        if(mb_strlen($giftMessage)>300) throw new \InvalidArgumentException('Gift message must be 300 characters or fewer.');
        $gift=(new GiftService(Database::connection()))->normalizeCheckout($data);
        return [
            'email'=>$email,
            'first_name'=>trim((string)$data['first_name']),
            'last_name'=>trim((string)$data['last_name']),
            'line1'=>trim((string)$data['line1']),
            'line2'=>trim((string)($data['line2']??'')),
            'city'=>trim((string)$data['city']),
            'region'=>strtoupper(trim((string)$data['region'])),
            'postal_code'=>trim((string)$data['postal_code']),
            'country'=>strtoupper(trim((string)($data['country']??'US'))),
            'phone'=>trim((string)($data['phone']??'')),
            'gift_message'=>$giftMessage,
            'is_gift'=>!empty($data['is_gift']),
            'hide_price'=>$gift['hide_price'],
            'gift_packaging'=>$gift['gift_packaging'],
            'gift_delivery_date'=>$gift['gift_delivery_date'],
            'gift_recipient_email'=>$gift['gift_recipient_email'],
        ];
    }
}
