<?php
declare(strict_types=1);

namespace FudgeDonuts;

final class SeoService
{
    private const INDEXABLE_PATHS=[
        '/','/index.php','/flavor.php','/preset.php','/builder.php','/story.php','/faq.php','/policy.php','/contact.php','/gift-cards.php','/sitemap.php','/robots.php','/robots.txt'
    ];
    public function __construct(private readonly CatalogRepository $catalog,private string $baseUrl)
    {
        $this->baseUrl=rtrim($this->baseUrl,'/');
    }

    public function canonical(string $path): string
    {
        return $this->baseUrl.'/'.ltrim($path,'/');
    }

    public static function indexable(string $uri): bool
    {
        $path=parse_url($uri,PHP_URL_PATH)?:'/';
        return in_array($path,self::INDEXABLE_PATHS,true);
    }

    public static function applyRobotsHeader(string $uri): void
    {
        if(headers_sent()) return;
        if(!self::indexable($uri)) header('X-Robots-Tag: noindex, nofollow, noarchive');
    }

    public function organizationSchema(): array
    {
        return [
            '@context'=>'https://schema.org',
            '@type'=>'Organization',
            'name'=>'Fudge Donuts',
            'url'=>$this->canonical('/'),
            'logo'=>$this->canonical('/images/hero.png'),
        ];
    }

    public function flavorSchema(array $flavor): array
    {
        return [
            '@context'=>'https://schema.org',
            '@type'=>'Product',
            'name'=>$flavor['name'],
            'description'=>$flavor['description'],
            'image'=>$this->canonical($flavor['image_path']?:'/images/placeholder.png'),
            'brand'=>['@type'=>'Brand','name'=>'Fudge Donuts'],
            'url'=>$this->canonical('/flavor.php?slug='.rawurlencode((string)$flavor['slug'])),
            'additionalProperty'=>[
                [
                    '@type'=>'PropertyValue',
                    'name'=>'Availability',
                    'value'=>(int)$flavor['sold_out']?'Sold out':'Available in build-your-own boxes'
                ]
            ],
        ];
    }

    public function presetSchema(array $box): array
    {
        return [
            '@context'=>'https://schema.org',
            '@type'=>'Product',
            'name'=>$box['name'],
            'description'=>'Curated '.(int)$box['size'].' pack from Fudge Donuts.',
            'sku'=>'preset-'.(string)$box['preset_slug'],
            'image'=>$box['image_path']?$this->canonical($box['image_path']):null,
            'brand'=>['@type'=>'Brand','name'=>'Fudge Donuts'],
            'offers'=>[
                '@type'=>'Offer',
                'priceCurrency'=>'USD',
                'price'=>number_format((int)$box['total_cents']/100,2,'.',''),
                'availability'=>'https://schema.org/InStock',
                'url'=>$this->canonical('/preset.php?slug='.rawurlencode((string)$box['preset_slug'])),
            ],
        ];
    }

    public function sitemapEntries(): array
    {
        $entries=[
            ['path'=>'/','changefreq'=>'weekly','priority'=>'1.0'],
            ['path'=>'/story.php','changefreq'=>'monthly','priority'=>'0.6'],
            ['path'=>'/faq.php','changefreq'=>'monthly','priority'=>'0.5'],
            ['path'=>'/contact.php','changefreq'=>'monthly','priority'=>'0.4'],
            ['path'=>'/gift-cards.php','changefreq'=>'monthly','priority'=>'0.7'],
            ['path'=>'/policy.php?type=terms','changefreq'=>'yearly','priority'=>'0.2'],
            ['path'=>'/policy.php?type=privacy','changefreq'=>'yearly','priority'=>'0.2'],
            ['path'=>'/policy.php?type=refunds','changefreq'=>'yearly','priority'=>'0.2'],
            ['path'=>'/policy.php?type=shipping','changefreq'=>'monthly','priority'=>'0.3'],
        ];
        foreach($this->catalog->packs() as $pack)$entries[]=['path'=>'/builder.php?size='.(int)$pack['size'],'changefreq'=>'weekly','priority'=>'0.8'];
        foreach($this->catalog->presets() as $preset)$entries[]=['path'=>'/preset.php?slug='.rawurlencode((string)$preset['slug']),'changefreq'=>'weekly','priority'=>'0.8'];
        foreach($this->catalog->flavors() as $flavor)$entries[]=['path'=>'/flavor.php?slug='.rawurlencode((string)$flavor['slug']),'changefreq'=>'weekly','priority'=>'0.7'];
        $seen=[];$out=[];
        foreach($entries as $entry){if(isset($seen[$entry['path']]))continue;$seen[$entry['path']]=true;$out[]=$entry;}
        return $out;
    }

    public function sitemapPaths(): array
    {
        return array_column($this->sitemapEntries(),'path');
    }
}
