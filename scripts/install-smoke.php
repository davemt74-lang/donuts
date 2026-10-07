<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/bootstrap.php';

$base=rtrim((string)env('INSTALL_BASE_URL','http://127.0.0.1:8100'),'/');
if(!extension_loaded('curl')) throw new RuntimeException('cURL extension is required for installer smoke tests.');

$cookie=tempnam(sys_get_temp_dir(),'fudge-install-cookie-');
if($cookie===false) throw new RuntimeException('Unable to create installer smoke cookie jar.');

function installRequest(string $base,string $path,string $method='GET',array $fields=[]): array
{
    global $cookie;
    $headers=[];
    $ch=curl_init($base.$path);
    $options=[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>3,
        CURLOPT_TIMEOUT=>15,
        CURLOPT_USERAGENT=>'FudgeDonutsInstallerSmoke/1.0',
        CURLOPT_COOKIEJAR=>$cookie,
        CURLOPT_COOKIEFILE=>$cookie,
        CURLOPT_HEADERFUNCTION=>static function($ch,string $line) use (&$headers): int {
            $len=strlen($line);$line=trim($line);
            if($line==='' || !str_contains($line,':')) return $len;
            [$name,$value]=explode(':',$line,2);
            $headers[strtolower(trim($name))][]=trim($value);
            return $len;
        },
    ];
    if($method==='POST'){
        $options[CURLOPT_POST]=true;
        $options[CURLOPT_POSTFIELDS]=http_build_query($fields);
        $options[CURLOPT_HTTPHEADER]=['Content-Type: application/x-www-form-urlencoded'];
    }
    curl_setopt_array($ch,$options);
    $body=curl_exec($ch);
    if($body===false){
        $error=curl_error($ch);curl_close($ch);
        throw new RuntimeException('HTTP request failed for '.$path.': '.$error);
    }
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status'=>$status,'headers'=>$headers,'body'=>(string)$body];
}

function installHeader(array $response,string $name): string
{
    return implode(', ',$response['headers'][strtolower($name)]??[]);
}

function installAssert(bool $ok,string $message): void
{
    if(!$ok) throw new RuntimeException($message);
}

try{
    $root=installRequest($base,'/');
    installAssert($root['status']===302,'Fresh root must redirect to installer.');
    installAssert(installHeader($root,'location')==='/install.php','Fresh root redirect target must be /install.php.');

    $page=installRequest($base,'/install.php');
    installAssert($page['status']===200,'Installer must load on a fresh database.');
    installAssert(str_contains($page['body'],'Create the first administrator'),'Installer must show first-user form.');
    installAssert(preg_match('/name="_csrf" value="([^"]+)"/',$page['body'],$m)===1,'Installer CSRF token missing.');

    $post=installRequest($base,'/install.php','POST',[
        '_csrf'=>html_entity_decode($m[1],ENT_QUOTES),
        'first_name'=>'Install',
        'last_name'=>'Owner',
        'email'=>'install-owner@example.com',
        'password'=>'InstallerPass123',
        'password_confirmation'=>'InstallerPass123',
    ]);
    installAssert($post['status']===302,'First-user submit must redirect.');
    installAssert(installHeader($post,'location')==='/admin.php','First-user submit must redirect to Admin.');

    $locked=installRequest($base,'/install.php');
    installAssert($locked['status']===302,'Installer must lock after first-user creation.');
    installAssert(installHeader($locked,'location')==='/admin.php','Locked installer must redirect to Admin.');

    $home=installRequest($base,'/');
    installAssert($home['status']===200,'Storefront must load after installation.');
    installAssert(str_contains($home['body'],'Fudge Donuts'),'Storefront body missing after installation.');

    $admin=installRequest($base,'/admin.php');
    installAssert($admin['status']===200,'Admin must load after installer login.');
    installAssert(str_contains($admin['body'],'Dashboard'),'Admin dashboard missing after installation.');

    echo "Fresh web installer HTTP flow passed\n";
}finally{
    @unlink($cookie);
}
