<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class AdminAuthService
{
    public function __construct(private readonly PDO $db) {}

    public function isInstalled(): bool
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() > 0;
    }

    public function createFirstAdmin(array $data): int
    {
        $this->db->beginTransaction();
        try {
            if ((int)$this->db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() > 0) {
                throw new \RuntimeException('Administrator setup is already complete.');
            }
            try {
                $lock=$this->db->prepare("INSERT INTO installation_state(state_key) VALUES('admin_setup')");
                $lock->execute();
            } catch (\PDOException) {
                throw new \RuntimeException('Administrator setup is already complete.');
            }
            $id=$this->insertAdmin($data,'super_admin',null);
            $this->db->commit();
            try{(new AdminAuditService($this->db))->record($id,'admin_account_created','admin',$id,'Initial Super Admin created.');}catch(\Throwable){}
            return $id;
        } catch (\Throwable $e) {
            if($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function createAdmin(array $data,string $role,int $createdBy): int
    {
        if(!in_array($role,['super_admin','admin','fulfillment'],true)){
            throw new \InvalidArgumentException('Invalid administrator role.');
        }
        $id=$this->insertAdmin($data,$role,$createdBy);
        (new AdminAuditService($this->db))->record($createdBy,'admin_account_changed','admin',$id,'Administrator created with role '.$role,[],['email'=>strtolower(trim((string)($data['email']??''))),'role'=>$role,'active'=>1]);
        return $id;
    }

    public function authenticate(string $email,string $password): ?array
    {
        $email=strtolower(trim($email));
        $s=$this->db->prepare('SELECT * FROM admin_users WHERE email=? AND active=1');
        $s->execute([$email]);
        $admin=$s->fetch();
        if(!$admin || !password_verify($password,(string)$admin['password_hash'])) return null;

        if(password_needs_rehash((string)$admin['password_hash'],PASSWORD_DEFAULT)){
            $u=$this->db->prepare('UPDATE admin_users SET password_hash=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $u->execute([password_hash($password,PASSWORD_DEFAULT),(int)$admin['id']]);
        }
        $u=$this->db->prepare('UPDATE admin_users SET last_login_at=CURRENT_TIMESTAMP WHERE id=?');
        $u->execute([(int)$admin['id']]);
        unset($admin['password_hash']);
        (new AdminAuditService($this->db))->record((int)$admin['id'],'login_success','admin',(int)$admin['id'],'Administrator signed in.');
        return $admin;
    }

    public function admin(int $id): ?array
    {
        $s=$this->db->prepare('SELECT id,email,first_name,last_name,role,active,last_login_at,created_at FROM admin_users WHERE id=?');
        $s->execute([$id]);
        return $s->fetch()?:null;
    }

    public function all(): array
    {
        return $this->db->query('SELECT id,email,first_name,last_name,role,active,last_login_at,created_at FROM admin_users ORDER BY id')->fetchAll();
    }

    public function setActive(int $id,bool $active,int $actorId): void
    {
        if($id===$actorId && !$active) throw new \InvalidArgumentException('You cannot disable your own administrator account.');
        $target=$this->admin($id);
        if(!$target) throw new \InvalidArgumentException('Administrator not found.');

        if(!$active && $target['role']==='super_admin'){
            $s=$this->db->prepare("SELECT COUNT(*) FROM admin_users WHERE role='super_admin' AND active=1 AND id<>?");
            $s->execute([$id]);
            if((int)$s->fetchColumn()===0) throw new \InvalidArgumentException('At least one active Super Admin is required.');
        }

        $before=$target;
        $s=$this->db->prepare('UPDATE admin_users SET active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $s->execute([$active?1:0,$id]);
        (new AdminAuditService($this->db))->record($actorId,'admin_account_changed','admin',$id,$active?'Administrator enabled.':'Administrator disabled.',$before,['active'=>$active?1:0]);
    }

    public function changePassword(int $id,string $currentPassword,string $newPassword): void
    {
        $s=$this->db->prepare('SELECT password_hash FROM admin_users WHERE id=? AND active=1');
        $s->execute([$id]);$hash=$s->fetchColumn();
        if(!$hash || !password_verify($currentPassword,(string)$hash)) throw new \InvalidArgumentException('Current password is incorrect.');
        $this->validatePassword($newPassword);
        $u=$this->db->prepare('UPDATE admin_users SET password_hash=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $u->execute([password_hash($newPassword,PASSWORD_DEFAULT),$id]);
        (new AdminAuditService($this->db))->record($id,'password_changed','admin',$id,'Administrator changed their password.');
    }

    private function insertAdmin(array $data,string $role,?int $createdBy): int
    {
        $email=strtolower(trim((string)($data['email']??'')));
        $first=trim((string)($data['first_name']??''));
        $last=trim((string)($data['last_name']??''));
        $password=(string)($data['password']??'');
        $confirm=(string)($data['password_confirmation']??'');

        if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Enter a valid email address.');
        if($first==='' || $last==='') throw new \InvalidArgumentException('First and last name are required.');
        if($password!==$confirm) throw new \InvalidArgumentException('Passwords do not match.');
        $this->validatePassword($password);

        $s=$this->db->prepare('INSERT INTO admin_users(email,password_hash,first_name,last_name,role,created_by) VALUES(?,?,?,?,?,?)');
        try {
            $s->execute([$email,password_hash($password,PASSWORD_DEFAULT),$first,$last,$role,$createdBy]);
        } catch(\PDOException $e) {
            if(str_contains(strtolower($e->getMessage()),'unique')) throw new \InvalidArgumentException('An administrator with that email already exists.');
            throw $e;
        }
        return (int)$this->db->lastInsertId();
    }

    private function validatePassword(string $password): void
    {
        if(strlen($password)<12) throw new \InvalidArgumentException('Password must be at least 12 characters.');
        if(!preg_match('/[A-Z]/',$password) || !preg_match('/[a-z]/',$password) || !preg_match('/\d/',$password)){
            throw new \InvalidArgumentException('Password must include uppercase, lowercase, and a number.');
        }
    }
}
