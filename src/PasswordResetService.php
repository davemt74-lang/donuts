<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class PasswordResetService
{
    public function __construct(private readonly PDO $db) {}

    public function create(string $email,int $ttlSeconds=3600): ?array
    {
        $email=strtolower(trim($email));
        $s=$this->db->prepare('SELECT id,email,first_name FROM users WHERE email=?');
        $s->execute([$email]);$user=$s->fetch();
        if(!$user) return null;

        $raw=bin2hex(random_bytes(32));
        $hash=hash('sha256',$raw);
        $expires=gmdate('Y-m-d H:i:s',time()+max(300,min(86400,$ttlSeconds)));
        $this->db->beginTransaction();
        try{
            $d=$this->db->prepare('DELETE FROM password_reset_tokens WHERE user_id=? AND used_at IS NULL');
            $d->execute([(int)$user['id']]);
            $i=$this->db->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at) VALUES(?,?,?)');
            $i->execute([(int)$user['id'],$hash,$expires]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        return ['token'=>$raw,'email'=>$user['email'],'first_name'=>$user['first_name'],'expires_at'=>$expires];
    }

    public function consume(string $rawToken,string $newPassword,string $confirmation): int
    {
        if($newPassword!==$confirmation) throw new \InvalidArgumentException('Passwords do not match.');
        $this->validatePassword($newPassword);
        $hash=hash('sha256',trim($rawToken));
        $s=$this->db->prepare('SELECT * FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>CURRENT_TIMESTAMP');
        $s->execute([$hash]);$token=$s->fetch();
        if(!$token) throw new \InvalidArgumentException('This password reset link is invalid or has expired.');

        $this->db->beginTransaction();
        try{
            $u=$this->db->prepare('UPDATE users SET password_hash=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $u->execute([password_hash($newPassword,PASSWORD_DEFAULT),(int)$token['user_id']]);
            $m=$this->db->prepare('UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE id=? AND used_at IS NULL');
            $m->execute([(int)$token['id']]);
            if($m->rowCount()!==1) throw new \RuntimeException('Password reset token was already used.');
            $d=$this->db->prepare('UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE user_id=? AND used_at IS NULL');
            $d->execute([(int)$token['user_id']]);
            $this->db->commit();
            return (int)$token['user_id'];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function validatePassword(string $password): void
    {
        if(strlen($password)<12) throw new \InvalidArgumentException('Password must be at least 12 characters.');
        if(!preg_match('/[A-Za-z]/',$password) || !preg_match('/\d/',$password)) throw new \InvalidArgumentException('Password must contain letters and a number.');
    }
}
