<?php
declare(strict_types=1);

namespace FudgeDonuts;

final class InstallerService
{
    public function __construct(private readonly string $root) {}

    public function needsInstallation(): bool
    {
        if(!extension_loaded('pdo_sqlite')) return true;

        $dsn=(string)\env('DB_DSN','sqlite:storage/store.sqlite');
        if(!str_starts_with($dsn,'sqlite:')) return false;

        $path=$this->sqlitePath($dsn);
        if($path!==null && !is_file($path)) return true;

        try{
            $db=Database::connection();
            if($db->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='sqlite') return false;
            $table=$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='admin_users'")->fetchColumn();
            if(!$table) return true;
            return (int)$db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn()===0;
        }catch(\Throwable){
            return true;
        }
    }

    public function requirements(): array
    {
        $storage=$this->root.'/storage';
        $storageReady=is_dir($storage) ? is_writable($storage) : is_writable($this->root);
        return [
            ['name'=>'PHP 8.1+','ok'=>version_compare(PHP_VERSION,'8.1.0','>='),
             'message'=>'PHP '.PHP_VERSION],
            ['name'=>'PDO SQLite','ok'=>extension_loaded('pdo_sqlite'),
             'message'=>extension_loaded('pdo_sqlite')?'pdo_sqlite is available':'Enable the pdo_sqlite PHP extension'],
            ['name'=>'Storage','ok'=>$storageReady,
             'message'=>$storageReady?'storage/ can be created or written':'Make the application directory or storage/ writable'],
            ['name'=>'Migrations','ok'=>is_dir($this->root.'/database'),
             'message'=>is_dir($this->root.'/database')?'database migrations are available':'database/ is missing'],
        ];
    }

    private function sqlitePath(string $dsn): ?string
    {
        $path=substr($dsn,7);
        if($path==='' || $path===':memory:') return null;
        if(str_starts_with($path,'/') || preg_match('/^[A-Za-z]:[\\\\\/]/',$path)===1) return $path;
        return $this->root.'/'.ltrim($path,'/\\\\');
    }

    public function prepareDatabase(): array
    {
        $failed=array_values(array_filter($this->requirements(),static fn(array $row): bool => !$row['ok']));
        if($failed){
            throw new \RuntimeException(implode(' ',array_column($failed,'message')));
        }

        $storage=$this->root.'/storage';
        if(!is_dir($storage) && !mkdir($storage,0775,true) && !is_dir($storage)){
            throw new \RuntimeException('Unable to create storage/.');
        }
        if(!is_writable($storage)){
            throw new \RuntimeException('storage/ is not writable.');
        }

        $db=Database::connection();
        if($db->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='sqlite'){
            throw new \RuntimeException('The web installer supports the certified SQLite database only.');
        }

        $migrations=new MigrationService($db,$this->root.'/database');
        $before=$migrations->status();
        if($before['drift']>0){
            throw new \RuntimeException('Database migration drift was detected. Installation was stopped.');
        }

        $applied=$migrations->applyPending();
        $migrations->assertClean();
        $after=$migrations->status();

        return [
            'applied'=>count($applied),
            'total'=>(int)$after['applied'],
            'pending'=>(int)$after['pending'],
            'drift'=>(int)$after['drift'],
        ];
    }
}
