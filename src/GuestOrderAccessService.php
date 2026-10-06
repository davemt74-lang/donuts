<?php
declare(strict_types=1);

namespace FudgeDonuts;

final class GuestOrderAccessService
{
    public function __construct(
        private readonly string $secret,
        private readonly string $baseUrl,
        private readonly int $ttlDays=90
    ){
        if(strlen($this->secret)<32) throw new \RuntimeException('APP_KEY must be at least 32 characters for signed order links.');
    }

    public function link(array $order,?int $now=null): string
    {
        $now ??= time();
        $days=max(1,min(365,$this->ttlDays));
        $payload=$this->encode(json_encode([
            'order_id'=>(int)$order['id'],
            'exp'=>$now+($days*86400),
        ],JSON_THROW_ON_ERROR));
        $sig=$this->encode(hash_hmac('sha256',$payload,$this->secret,true));
        return rtrim($this->baseUrl,'/').'/order-status.php?order='.rawurlencode((string)$order['order_number']).'&token='.rawurlencode($payload.'.'.$sig);
    }

    public function verify(array $order,string $token,?int $now=null): bool
    {
        $now ??= time();
        if(strlen($token)>1000 || !str_contains($token,'.')) return false;
        [$payload,$sig]=array_pad(explode('.',$token,2),2,'');
        if($payload==='' || $sig==='') return false;
        $expected=$this->encode(hash_hmac('sha256',$payload,$this->secret,true));
        if(!hash_equals($expected,$sig)) return false;
        $decoded=$this->decode($payload);
        if($decoded===null) return false;
        try{$data=json_decode($decoded,true,512,JSON_THROW_ON_ERROR);}catch(\Throwable){return false;}
        return is_array($data)
            && (int)($data['order_id']??0)===(int)$order['id']
            && (int)($data['exp']??0)>=$now;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value),'+/','-_'),'=');
    }

    private function decode(string $value): ?string
    {
        if(!preg_match('/^[A-Za-z0-9_-]+$/',$value)) return null;
        $pad=strlen($value)%4;
        if($pad) $value.=str_repeat('=',4-$pad);
        $decoded=base64_decode(strtr($value,'-_','+/'),true);
        return $decoded===false?null:$decoded;
    }
}
