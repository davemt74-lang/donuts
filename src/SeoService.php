<?php
declare(strict_types=1);

namespace FudgeDonuts;

final class SeoService
{
    public function __construct(
        private readonly CatalogRepository $catalog,
        private string $baseUrl,
        private readonly ?StoreSettingsService $settings=null
    ){
        $this->baseUrl=rtrim($this->baseUrl,'/');
    }

    public function canonical(string $path): string
    {
        return $this->baseUrl.'/'.ltrim($path,'/');
    }

    public function organizationSchema(): array
    {
        $s=$this->settings?->all()??[];
        $name=trim((string)($s['store_name']??'Fudge Donuts'))?:'Fudge Donuts';
        $schema=[
            '@context'=>'https://schema.org',
            '@type'=>'Organization',
            'name'=>$name,
            'legalName'=>trim((string)($s['legal_name']??''))?:$name,
            'url'=>$this->canonical('/'),
            'logo'=>$this->canonical('/images/hero.png'),
        ];
        $email=trim((string)($s['contact_email']??''));
        if($email!=='')$schema['email']=$email;
        $phone=trim((string)($s['phone']??''));
        if($phone!=='')$schema['telephone']=$phone;
        $sameAs=array_values(array_filter([
            trim((string)($s['instagram_url']??'')),
            trim((string)($s['facebook_url']??'')),
        ]));
        if($sameAs)$schema['sameAs']=$sameAs;
        $address1=trim((string)($s['address_line1']??''));
        if($address1!==''){
            $schema['address']=[
                '@type'=>'PostalAddress',
                'streetAddress'=>trim($address1.' '.trim((string)($s['address_line2']??''))),
                'addressLocality'=>(string)($s['city']??''),
                'addressRegion'=>(string)($s['region']??''),
                'postalCode'=>(string)($s['postal_code']??''),
                'addressCountry'=>(string)($s['country']??'US'),
            ];
        }
        return $schema;
    }

    public function flavorSchema(array $flavor): array
    {
        return [
            '@context'=>'https://schema.org',
            '@type'=>'Product',
            'name'=>$flavor['name'],
            'description'=>$flavor['description'],
            'image'=>$this->canonical($flavor['image_path']?:'/images/placeholder.png'),
            'brand'=>['@type'=>'Brand','name'=>$this->brandName()],
            'offers'=>[
                '@type'=>'Offer',
                'priceCurrency'=>'USD',
                'availability'=>(int)$flavor['sold_out']?'https://schema.org/OutOfStock':'https://schema.org/InStock',
                'url'=>$this->canonical('/flavor.php?slug='.rawurlencode((string)$flavor['slug'])),
            ],
        ];
    }

    public function presetSchema(array $box): array
    {
        return [
            '@context'=>'https://schema.org',
            '@type'=>'Product',
            'name'=>$box['name'],
            'image'=>$box['image_path']?$this->canonical($box['image_path']):null,
            'brand'=>['@type'=>'Brand','name'=>$this->brandName()],
            'offers'=>[
                '@type'=>'Offer',
                'priceCurrency'=>'USD',
                'price'=>number_format((int)$box['total_cents']/100,2,'.',''),
                'availability'=>'https://schema.org/InStock',
                'url'=>$this->canonical('/preset.php?slug='.rawurlencode((string)$box['preset_slug'])),
            ],
        ];
    }

    private function brandName(): string
    {
        return $this->settings?->brandName()??'Fudge Donuts';
    }

    public function sitemapPaths(): array
    {
        $paths=['/','/story.php','/faq.php','/policy.php?type=terms','/policy.php?type=privacy','/policy.php?type=refunds','/policy.php?type=shipping'];
        foreach($this->catalog->packs() as $pack)$paths[]='/builder.php?size='.(int)$pack['size'];
        foreach($this->catalog->presets() as $preset)$paths[]='/preset.php?slug='.rawurlencode((string)$preset['slug']);
        foreach($this->catalog->flavors() as $flavor)$paths[]='/flavor.php?slug='.rawurlencode((string)$flavor['slug']);
        return array_values(array_unique($paths));
    }
}
