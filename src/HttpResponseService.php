<?php
declare(strict_types=1);

namespace FudgeDonuts;

final class HttpResponseService
{
    public static function render(
        int $status,
        string $title,
        string $message,
        array $actions=[],
        ?string $reference=null,
        bool $admin=false
    ): string {
        $status=self::status($status);
        $title=trim($title)!==''?trim($title):'Something went wrong';
        $message=trim($message)!==''?trim($message):'Please try again.';
        $safeActions=[];
        foreach($actions as $action){
            $label=trim((string)($action['label']??''));
            $href=trim((string)($action['href']??''));
            if($label==='' || !self::safeHref($href)) continue;
            $safeActions[]=['label'=>$label,'href'=>$href];
        }
        $brand=$admin?'Fudge Donuts Admin':'Fudge Donuts';
        $eyebrow=$admin?'Store administration':'Fudge Donuts';
        $statusText=(string)$status;
        $buttons='';
        foreach($safeActions as $i=>$action){
            $class=$i===0?'button':'button secondary';
            $buttons.='<a class="'.$class.'" href="'.htmlspecialchars($action['href'],ENT_QUOTES,'UTF-8').'">'.htmlspecialchars($action['label'],ENT_QUOTES,'UTF-8').'</a>';
        }
        $referenceHtml=$reference!==null && trim($reference)!==''
            ? '<p class="reference">Reference: <code>'.htmlspecialchars(trim($reference),ENT_QUOTES,'UTF-8').'</code></p>'
            : '';
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.
            htmlspecialchars($title,ENT_QUOTES,'UTF-8').' · '.htmlspecialchars($brand,ENT_QUOTES,'UTF-8').
            '</title><style>:root{color-scheme:light}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:28px;background:#fffaf4;color:#23150f;font:16px/1.55 Inter,system-ui,sans-serif}.card{width:min(680px,100%);background:#fff;border:1px solid #eadfd5;border-radius:26px;padding:clamp(28px,6vw,54px);box-shadow:0 24px 80px #2a160b12}.eyebrow{text-transform:uppercase;letter-spacing:.18em;font-size:.76rem;font-weight:800;color:#603522}.status{font:700 clamp(3rem,8vw,6rem) Georgia,serif;line-height:1;margin:.12em 0;color:#8b6049}h1{font:700 clamp(2.1rem,6vw,4rem)/1.05 Georgia,serif;margin:.18em 0 .35em}p{color:#66574e}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:26px}.button{display:inline-block;background:#23150f;color:#fff;text-decoration:none;border-radius:999px;padding:13px 20px;font-weight:800}.button.secondary{background:#603522}.button:focus-visible{outline:3px solid #7b4d31;outline-offset:3px}.reference{margin-top:24px;font-size:.88rem;color:#78675c}code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;color:#23150f}@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important}}</style></head><body><main class="card"><p class="eyebrow">'.
            htmlspecialchars($eyebrow,ENT_QUOTES,'UTF-8').'</p><div class="status" aria-hidden="true">'.$statusText.'</div><h1>'.
            htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p>'.
            ($buttons!==''?'<div class="actions">'.$buttons.'</div>':'').$referenceHtml.'</main></body></html>';
    }

    public static function send(
        int $status,
        string $title,
        string $message,
        array $actions=[],
        ?string $reference=null,
        bool $admin=false,
        ?int $retryAfter=null
    ): never {
        $status=self::status($status);
        if(!headers_sent()){
            http_response_code($status);
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: private, no-store, max-age=0');
            header('Pragma: no-cache');
            if($retryAfter!==null && $retryAfter>0) header('Retry-After: '.min($retryAfter,86400));
        }
        echo self::render($status,$title,$message,$actions,$reference,$admin);
        exit;
    }

    private static function safeHref(string $href): bool
    {
        if($href==='') return false;
        if($href[0]==='/' && !str_starts_with($href,'//')) return true;
        return str_starts_with($href,'#');
    }

    private static function status(int $status): int
    {
        return $status>=400 && $status<=599?$status:500;
    }
}
