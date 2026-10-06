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

    public function addresses(int $userId): array
    {
        $s=$this->db->prepare('SELECT * FROM addresses WHERE user_id=? ORDER BY is_default DESC,id DESC');$s->execute([$userId]);return $s->fetchAll();
    }
}
