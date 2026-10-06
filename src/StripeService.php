<?php
declare(strict_types=1);

namespace FudgeDonuts;

final class StripeService
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $webhookSecret,
        private readonly string $apiBase='https://api.stripe.com'
    ) {}

    public function createCheckoutSession(array $params,string $idempotencyKey): array
    {
        if($this->secretKey==='') throw new \RuntimeException('Stripe secret key is not configured.');
        return $this->request('/v1/checkout/sessions',$params,$idempotencyKey);
    }

    public function createRefund(array $params,string $idempotencyKey): array
    {
        if($this->secretKey==='') throw new \RuntimeException('Stripe secret key is not configured.');
        return $this->request('/v1/refunds',$params,$idempotencyKey);
    }

    public function expireCheckoutSession(string $sessionId): array
    {
        if($this->secretKey==='') throw new \RuntimeException('Stripe secret key is not configured.');
        if(!preg_match('/^cs_[A-Za-z0-9_]+$/',$sessionId)) throw new \InvalidArgumentException('Invalid Stripe Checkout session ID.');
        return $this->request('/v1/checkout/sessions/'.rawurlencode($sessionId).'/expire',[],'expire_'.hash('sha256',$sessionId));
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        if($this->secretKey==='') throw new \RuntimeException('Stripe secret key is not configured.');
        if(!preg_match('/^cs_[A-Za-z0-9_]+$/',$sessionId)) throw new \InvalidArgumentException('Invalid Stripe Checkout session ID.');
        $ch=curl_init($this->apiBase.'/v1/checkout/sessions/'.rawurlencode($sessionId));
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$this->secretKey],
            CURLOPT_TIMEOUT=>20,
        ]);
        $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
        if($body===false || $error!=='') throw new \RuntimeException('Stripe request failed: '.$error);
        $decoded=json_decode((string)$body,true);
        if($status<200 || $status>=300 || !is_array($decoded)){
            $message=is_array($decoded)?($decoded['error']['message']??'Stripe request failed'):'Stripe request failed';
            throw new \RuntimeException((string)$message);
        }
        return $decoded;
    }

    public function verifyWebhook(string $payload,string $signatureHeader,int $tolerance=300,?int $now=null): bool
    {
        if($this->webhookSecret==='') return false;
        $parts=[];
        foreach(explode(',',$signatureHeader) as $part){
            [$k,$v]=array_pad(explode('=',trim($part),2),2,null);
            if($k!==null && $v!==null)$parts[$k][]=$v;
        }
        $timestamp=isset($parts['t'][0])?(int)$parts['t'][0]:0;
        $signatures=$parts['v1']??[];
        $now ??= time();
        if($timestamp<=0 || abs($now-$timestamp)>$tolerance || !$signatures) return false;
        $expected=hash_hmac('sha256',$timestamp.'.'.$payload,$this->webhookSecret);
        foreach($signatures as $sig) if(hash_equals($expected,$sig)) return true;
        return false;
    }

    private function request(string $path,array $params,string $idempotencyKey): array
    {
        $ch=curl_init($this->apiBase.$path);
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POSTFIELDS=>http_build_query($params),
            CURLOPT_HTTPHEADER=>[
                'Authorization: Bearer '.$this->secretKey,
                'Idempotency-Key: '.$idempotencyKey,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT=>20,
        ]);
        $body=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        $error=curl_error($ch);
        curl_close($ch);
        if($body===false || $error!=='') throw new \RuntimeException('Stripe request failed: '.$error);
        $decoded=json_decode((string)$body,true);
        if($status<200 || $status>=300 || !is_array($decoded)){
            $message=is_array($decoded)?($decoded['error']['message']??'Stripe request failed'):'Stripe request failed';
            throw new \RuntimeException((string)$message);
        }
        return $decoded;
    }
}
