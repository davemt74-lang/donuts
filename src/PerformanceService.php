<?php
declare(strict_types=1);

namespace FudgeDonuts;

final class PerformanceService
{
    private const PUBLIC_READ_ONLY=[
        '/flavor.php','/story.php','/faq.php','/policy.php','/sitemap.php'
    ];

    public static function canSkipSession(string $method,string $uri,bool $hasSessionCookie): bool
    {
        if($hasSessionCookie)return false;
        $method=strtoupper($method);$path=parse_url($uri,PHP_URL_PATH)?:'/';
        return in_array($method,['GET','HEAD'],true) && in_array($path,self::PUBLIC_READ_ONLY,true);
    }

    public static function policy(string $method,string $uri,bool $authenticated=false): array
    {
        $method=strtoupper($method);$path=parse_url($uri,PHP_URL_PATH)?:'/';
        if($authenticated || !in_array($method,['GET','HEAD'],true)){
            return ['public'=>false,'cache_control'=>'private, no-store, max-age=0'];
        }
        if(in_array($path,self::PUBLIC_READ_ONLY,true)){
            return ['public'=>true,'cache_control'=>'public, max-age=300, stale-while-revalidate=60'];
        }
        return ['public'=>false,'cache_control'=>'private, no-store, max-age=0'];
    }

    public static function apply(string $method,string $uri,bool $authenticated=false): void
    {
        if(headers_sent())return;
        $policy=self::policy($method,$uri,$authenticated);
        header('Cache-Control: '.$policy['cache_control']);
        header('Vary: Accept-Encoding');
        if(!$policy['public'])header('Pragma: no-cache');
    }

    public static function assetUrl(string $path,string $root): string
    {
        if($path==='' || $path[0]!=='/') throw new \InvalidArgumentException('Asset path must be absolute.');
        if(str_contains($path,'..')) throw new \InvalidArgumentException('Asset path cannot traverse directories.');
        $file=rtrim($root,'/\\').'/public'.$path;
        $version=is_file($file)?(string)filemtime($file):'dev';
        return $path.'?v='.rawurlencode($version);
    }
}
