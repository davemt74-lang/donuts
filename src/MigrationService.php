<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class MigrationService
{
    public function __construct(private readonly PDO $db,private readonly string $migrationDir)
    {
        $this->bootstrapRegistry();
    }

    public function status(): array
    {
        $files=$this->files();$applied=$this->applied();$rows=[];$pending=0;$drift=0;
        foreach($files as $path){
            $name=basename($path);$checksum=hash_file('sha256',$path);$record=$applied[$name]??null;
            $state='pending';
            if($record){
                $state=hash_equals((string)$record['checksum'],$checksum)?'applied':'drift';
            }
            if($state==='pending')$pending++;
            if($state==='drift')$drift++;
            $rows[]=['file'=>$name,'checksum'=>$checksum,'state'=>$state,'applied_at'=>$record['applied_at']??null];
        }
        return ['migrations'=>$rows,'pending'=>$pending,'drift'=>$drift,'applied'=>count($applied)];
    }

    public function applyPending(): array
    {
        $status=$this->status();
        if($status['drift']>0) throw new \RuntimeException('Applied migration checksum drift detected. Refusing to continue.');
        $applied=[];
        foreach($status['migrations'] as $row){
            if($row['state']!=='pending') continue;
            $path=$this->migrationDir.'/'.$row['file'];
            $sql=(string)file_get_contents($path);$started=microtime(true);
            $this->db->beginTransaction();
            try{
                $this->db->exec($sql);
                $duration=(int)round((microtime(true)-$started)*1000);
                $s=$this->db->prepare('INSERT INTO schema_migrations(filename,checksum,duration_ms) VALUES(?,?,?)');
                $s->execute([$row['file'],$row['checksum'],$duration]);
                $this->db->commit();
                $applied[]=['file'=>$row['file'],'duration_ms'=>$duration];
            }catch(\Throwable $e){
                if($this->db->inTransaction())$this->db->rollBack();
                throw new \RuntimeException('Migration '.$row['file'].' failed: '.$e->getMessage(),0,$e);
            }
        }
        return $applied;
    }

    public function assertClean(): void
    {
        $status=$this->status();
        if($status['drift']>0) throw new \RuntimeException('Migration checksum drift detected.');
        if($status['pending']>0) throw new \RuntimeException($status['pending'].' pending database migration(s).');
    }

    private function bootstrapRegistry(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            filename VARCHAR(190) PRIMARY KEY,
            checksum VARCHAR(64) NOT NULL,
            duration_ms INTEGER NOT NULL DEFAULT 0,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
    }

    private function files(): array
    {
        $files=glob(rtrim($this->migrationDir,'/\\').'/*.sql')?:[];sort($files,SORT_STRING);return $files;
    }

    private function applied(): array
    {
        $out=[];foreach($this->db->query('SELECT * FROM schema_migrations ORDER BY filename')->fetchAll() as $row)$out[(string)$row['filename']]=$row;return $out;
    }
}
