<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class BackupService
{
    public function __construct(private readonly PDO $db,private readonly string $root) {}

    public function databasePath(): string
    {
        $rows=$this->db->query('PRAGMA database_list')->fetchAll();
        foreach($rows as $row){
            if(($row['name']??'')==='main' && !empty($row['file'])) return (string)$row['file'];
        }
        throw new \RuntimeException('Backup service requires a file-backed SQLite database.');
    }

    public function backupDirectory(): string
    {
        $dir=$this->root.'/storage/backups';
        if(!is_dir($dir) && !mkdir($dir,0770,true) && !is_dir($dir)) throw new \RuntimeException('Unable to create backup directory.');
        return $dir;
    }

    public function create(string $reason='scheduled'): array
    {
        $dir=$this->backupDirectory();
        $stamp=gmdate('Ymd-His');
        $suffix=bin2hex(random_bytes(3));
        $path=$dir.'/store-'.$stamp.'-'.$suffix.'.sqlite';
        $escaped=str_replace("'","''",$path);
        $this->db->exec("VACUUM INTO '{$escaped}'");
        $check=$this->verify($path);
        if(!$check['ok']){ @unlink($path); throw new \RuntimeException('Backup integrity verification failed.'); }
        $meta=[
            'file'=>basename($path),
            'path'=>$path,
            'bytes'=>(int)filesize($path),
            'sha256'=>hash_file('sha256',$path),
            'reason'=>$reason,
            'created_at'=>gmdate('c'),
        ];
        file_put_contents($path.'.json',json_encode($meta,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);
        return $meta;
    }

    public function verify(string $path): array
    {
        $real=realpath($path);
        if($real===false || !is_file($real)) return ['ok'=>false,'message'=>'Backup file not found.'];
        try{
            $pdo=new PDO('sqlite:'.$real,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $result=(string)$pdo->query('PRAGMA integrity_check')->fetchColumn();
            $pdo=null;
            return ['ok'=>$result==='ok','message'=>$result];
        }catch(\Throwable $e){
            return ['ok'=>false,'message'=>$e->getMessage()];
        }
    }

    public function list(): array
    {
        $files=glob($this->backupDirectory().'/store-*.sqlite')?:[];
        rsort($files,SORT_STRING);$rows=[];
        foreach($files as $path){
            $rows[]=[
                'file'=>basename($path),
                'path'=>$path,
                'bytes'=>(int)filesize($path),
                'sha256'=>hash_file('sha256',$path),
                'modified_at'=>gmdate('c',(int)filemtime($path)),
            ];
        }
        return $rows;
    }

    public function prune(int $retentionDays=14,int $maxBackups=60): int
    {
        $retentionDays=max(1,min(365,$retentionDays));$maxBackups=max(2,min(500,$maxBackups));
        $rows=$this->list();$cutoff=time()-($retentionDays*86400);$deleted=0;
        foreach($rows as $idx=>$row){
            $mtime=(int)filemtime($row['path']);
            if($idx<$maxBackups && $mtime>=$cutoff) continue;
            if(@unlink($row['path'])){$deleted++;@unlink($row['path'].'.json');}
        }
        return $deleted;
    }

    public function resolveBackup(string $file): string
    {
        $file=basename(trim($file));
        if(!preg_match('/^store-[A-Za-z0-9-]+\.sqlite$/',$file)) throw new \InvalidArgumentException('Invalid backup filename.');
        $path=$this->backupDirectory().'/'.$file;
        $real=realpath($path);$dir=realpath($this->backupDirectory());
        if($real===false || $dir===false || !str_starts_with($real,$dir.DIRECTORY_SEPARATOR)) throw new \InvalidArgumentException('Backup not found.');
        return $real;
    }
}
