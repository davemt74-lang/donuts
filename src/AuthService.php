<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class AuthService
{
    public function __construct(private readonly PDO $db) {}

    public function register(string $email,string $password,string $firstName='',string $lastName=''): int
    {
        $email=strtolower(trim($email));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Enter a valid email address.');
        if(strlen($password)<10) throw new \InvalidArgumentException('Password must be at least 10 characters.');
        $hash=password_hash($password,PASSWORD_DEFAULT);
        $s=$this->db->prepare('INSERT INTO users(email,password_hash,first_name,last_name) VALUES(?,?,?,?)');
        try{$s->execute([$email,$hash,trim($firstName),trim($lastName)]);}
        catch(\PDOException $e){if(str_contains(strtolower($e->getMessage()),'unique')) throw new \InvalidArgumentException('An account already exists for that email.'); throw $e;}
        return (int)$this->db->lastInsertId();
    }

    public function login(string $email,string $password): ?array
    {
        $s=$this->db->prepare('SELECT * FROM users WHERE email=?');
        $s->execute([strtolower(trim($email))]);
        $user=$s->fetch();
        if(!$user || !password_verify($password,$user['password_hash'])) return null;
        if(password_needs_rehash($user['password_hash'],PASSWORD_DEFAULT)){
            $u=$this->db->prepare('UPDATE users SET password_hash=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $u->execute([password_hash($password,PASSWORD_DEFAULT),(int)$user['id']]);
        }
        unset($user['password_hash']);
        return $user;
    }

    public function user(int $id): ?array
    {
        $s=$this->db->prepare('SELECT id,email,first_name,last_name,marketing_opt_in,created_at FROM users WHERE id=?');
        $s->execute([$id]);
        return $s->fetch()?:null;
    }

    public function updateProfile(int $userId,array $data): void
    {
        $email=strtolower(trim((string)($data['email']??'')));
        $first=trim((string)($data['first_name']??''));$last=trim((string)($data['last_name']??''));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Enter a valid email address.');
        if($first==='' || $last==='') throw new \InvalidArgumentException('First and last name are required.');
        $s=$this->db->prepare('UPDATE users SET email=?,first_name=?,last_name=?,marketing_opt_in=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        try{$s->execute([$email,$first,$last,!empty($data['marketing_opt_in'])?1:0,$userId]);}
        catch(\PDOException $e){if(str_contains(strtolower($e->getMessage()),'unique')) throw new \InvalidArgumentException('That email address is already in use.');throw $e;}
    }

    public function saveAddress(int $userId,array $data): int
    {
        $required=['first_name','last_name','line1','city','region','postal_code'];
        foreach($required as $field) if(trim((string)($data[$field]??''))==='') throw new \InvalidArgumentException('Complete all required address fields.');
        $this->db->beginTransaction();
        try{
            if(!empty($data['is_default'])){
                $s=$this->db->prepare('UPDATE addresses SET is_default=0 WHERE user_id=?');$s->execute([$userId]);
            }
            $s=$this->db->prepare('INSERT INTO addresses(user_id,label,first_name,last_name,line1,line2,city,region,postal_code,country,phone,is_default) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$userId,trim((string)($data['label']??'Address')),trim((string)$data['first_name']),trim((string)$data['last_name']),trim((string)$data['line1']),trim((string)($data['line2']??'')),trim((string)$data['city']),trim((string)$data['region']),trim((string)$data['postal_code']),strtoupper(trim((string)($data['country']??'US'))),trim((string)($data['phone']??'')),!empty($data['is_default'])?1:0]);
            $id=(int)$this->db->lastInsertId();$this->db->commit();return $id;
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }

    public function updateAddress(int $userId,int $addressId,array $data): void
    {
        $required=['first_name','last_name','line1','city','region','postal_code'];
        foreach($required as $field) if(trim((string)($data[$field]??''))==='') throw new \InvalidArgumentException('Complete all required address fields.');
        $this->db->beginTransaction();
        try{
            if(!empty($data['is_default'])){
                $s=$this->db->prepare('UPDATE addresses SET is_default=0 WHERE user_id=?');$s->execute([$userId]);
            }
            $s=$this->db->prepare('UPDATE addresses SET label=?,first_name=?,last_name=?,line1=?,line2=?,city=?,region=?,postal_code=?,country=?,phone=?,is_default=? WHERE id=? AND user_id=?');
            $s->execute([trim((string)($data['label']??'Address')),trim((string)$data['first_name']),trim((string)$data['last_name']),trim((string)$data['line1']),trim((string)($data['line2']??'')),trim((string)$data['city']),trim((string)$data['region']),trim((string)$data['postal_code']),strtoupper(trim((string)($data['country']??'US'))),trim((string)($data['phone']??'')),!empty($data['is_default'])?1:0,$addressId,$userId]);
            if($s->rowCount()===0){
                $q=$this->db->prepare('SELECT 1 FROM addresses WHERE id=? AND user_id=?');$q->execute([$addressId,$userId]);
                if(!$q->fetchColumn()) throw new \InvalidArgumentException('Address not found.');
            }
            $this->ensureDefaultAddress($userId);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function deleteAddress(int $userId,int $addressId): void
    {
        $s=$this->db->prepare('DELETE FROM addresses WHERE id=? AND user_id=?');$s->execute([$addressId,$userId]);
        $this->ensureDefaultAddress($userId);
    }

    public function setDefaultAddress(int $userId,int $addressId): void
    {
        $this->db->beginTransaction();
        try{
            $q=$this->db->prepare('SELECT 1 FROM addresses WHERE id=? AND user_id=?');$q->execute([$addressId,$userId]);
            if(!$q->fetchColumn()) throw new \InvalidArgumentException('Address not found.');
            $s=$this->db->prepare('UPDATE addresses SET is_default=0 WHERE user_id=?');$s->execute([$userId]);
            $u=$this->db->prepare('UPDATE addresses SET is_default=1 WHERE id=? AND user_id=?');$u->execute([$addressId,$userId]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function ensureDefaultAddress(int $userId): void
    {
        $s=$this->db->prepare('SELECT COUNT(*) FROM addresses WHERE user_id=? AND is_default=1');$s->execute([$userId]);
        if((int)$s->fetchColumn()>0) return;
        $q=$this->db->prepare('SELECT id FROM addresses WHERE user_id=? ORDER BY id DESC LIMIT 1');$q->execute([$userId]);$id=$q->fetchColumn();
        if($id!==false){$u=$this->db->prepare('UPDATE addresses SET is_default=1 WHERE id=?');$u->execute([(int)$id]);}
    }

    public function addresses(int $userId): array
    {
        $s=$this->db->prepare('SELECT * FROM addresses WHERE user_id=? ORDER BY is_default DESC,id DESC');$s->execute([$userId]);return $s->fetchAll();
    }
}
