<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/src/HttpResponseService.php';

use FudgeDonuts\HttpResponseService;

$html=HttpResponseService::render(
    404,
    '<Missing>',
    'Try <again> safely.',
    [
        ['label'=>'Home','href'=>'/'],
        ['label'=>'Anchor','href'=>'#help'],
    ],
    'req-<123>'
);
assert(str_contains($html,'&lt;Missing&gt;'));
assert(str_contains($html,'Try &lt;again&gt; safely.'));
assert(str_contains($html,'href="/"'));
assert(str_contains($html,'href="#help"'));
assert(str_contains($html,'req-&lt;123&gt;'));
assert(str_contains($html,'noindex,nofollow'));

$admin=HttpResponseService::render(403,'Denied','No access',[['label'=>'Admin','href'=>'/admin.php']],null,true);
assert(str_contains($admin,'Fudge Donuts Admin'));
assert(str_contains($admin,'403'));

$rawResponses=[
    'public/flavor.php'=>'Flavor not found',
    'public/builder.php'=>'Pack not found',
    'public/pay.php'=>'Existing payment session could not be safely resumed',
];
foreach($rawResponses as $file=>$raw){
    $body=(string)file_get_contents($root.'/'.$file);
    assert(!str_contains($body,$raw),$file.' still contains a raw response');
}
$obs=(string)file_get_contents($root.'/src/ObservabilityService.php');
assert(str_contains($obs,'HttpResponseService::send'));
assert(str_contains($obs,'self::requestId()'));

echo "Section 53 checks passed\n";
