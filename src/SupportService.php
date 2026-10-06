<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class SupportService
{
    public function __construct(private readonly PDO $db) {}

    public function create(?int $userId,array $data): array
    {
        $email=strtolower(trim((string)($data['email']??'')));
        $name=trim((string)($data['customer_name']??''));
        $subject=trim((string)($data['subject']??''));
        $body=trim((string)($data['message']??''));
        $orderNumber=strtoupper(trim((string)($data['order_number']??'')));

        if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Enter a valid email address.');
        if($name==='' || mb_strlen($name)>190) throw new \InvalidArgumentException('Enter your name.');
        if(mb_strlen($subject)<3 || mb_strlen($subject)>190) throw new \InvalidArgumentException('Subject must be between 3 and 190 characters.');
        if(mb_strlen($body)<10 || mb_strlen($body)>5000) throw new \InvalidArgumentException('Message must be between 10 and 5000 characters.');

        $orderId=null;
        if($orderNumber!==''){
            $s=$this->db->prepare('SELECT id,user_id,email FROM orders WHERE order_number=?');$s->execute([$orderNumber]);$order=$s->fetch();
            if(!$order) throw new \InvalidArgumentException('Order number was not found.');
            if($userId){
                if((int)($order['user_id']??0)!==$userId) throw new \InvalidArgumentException('That order is not available for this account.');
            }elseif(strtolower((string)$order['email'])!==$email){
                throw new \InvalidArgumentException('Order email does not match.');
            }
            $orderId=(int)$order['id'];
        }

        $ticket='SUP-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare("INSERT INTO support_tickets(ticket_number,user_id,order_id,email,customer_name,subject,status,priority) VALUES(?,?,?,?,?,?,'open','normal')");
            $s->execute([$ticket,$userId,$orderId,$email,$name,$subject]);$id=(int)$this->db->lastInsertId();
            $m=$this->db->prepare("INSERT INTO support_messages(ticket_id,author_type,body) VALUES(?,'customer',?)");$m->execute([$id,$body]);
            $this->db->commit();return $this->ticket($id);
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function ticket(int $id): array
    {
        $s=$this->db->prepare('SELECT t.*,o.order_number,a.email assigned_admin_email FROM support_tickets t LEFT JOIN orders o ON o.id=t.order_id LEFT JOIN admin_users a ON a.id=t.assigned_admin_id WHERE t.id=?');
        $s->execute([$id]);$ticket=$s->fetch();
        if(!$ticket) throw new \InvalidArgumentException('Support ticket not found.');
        $m=$this->db->prepare('SELECT m.*,a.email admin_email FROM support_messages m LEFT JOIN admin_users a ON a.id=m.admin_id WHERE m.ticket_id=? ORDER BY m.id');
        $m->execute([$id]);$ticket['messages']=$m->fetchAll();return $ticket;
    }

    public function queue(?string $status=null,int $limit=200): array
    {
        $limit=max(1,min(500,$limit));
        if($status!==null && $status!==''){
            if(!in_array($status,['open','in_progress','waiting_customer','resolved','closed'],true)) throw new \InvalidArgumentException('Invalid support status.');
            $s=$this->db->prepare("SELECT t.*,o.order_number FROM support_tickets t LEFT JOIN orders o ON o.id=t.order_id WHERE t.status=? ORDER BY CASE t.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 ELSE 2 END,t.updated_at DESC LIMIT {$limit}");
            $s->execute([$status]);return $s->fetchAll();
        }
        return $this->db->query("SELECT t.*,o.order_number FROM support_tickets t LEFT JOIN orders o ON o.id=t.order_id ORDER BY CASE WHEN t.status IN ('open','in_progress','waiting_customer') THEN 0 ELSE 1 END,CASE t.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 ELSE 2 END,t.updated_at DESC LIMIT {$limit}")->fetchAll();
    }

    public function stats(): array
    {
        $out=['open'=>0,'in_progress'=>0,'waiting_customer'=>0,'resolved'=>0,'closed'=>0,'active'=>0];
        foreach($this->db->query('SELECT status,COUNT(*) count FROM support_tickets GROUP BY status')->fetchAll() as $row)$out[(string)$row['status']]=(int)$row['count'];
        $out['active']=$out['open']+$out['in_progress']+$out['waiting_customer'];return $out;
    }

    public function update(int $id,string $status,string $priority,?int $assignedAdminId): void
    {
        if(!in_array($status,['open','in_progress','waiting_customer','resolved','closed'],true)) throw new \InvalidArgumentException('Invalid support status.');
        if(!in_array($priority,['normal','high','urgent'],true)) throw new \InvalidArgumentException('Invalid support priority.');
        $s=$this->db->prepare('UPDATE support_tickets SET status=?,priority=?,assigned_admin_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $s->execute([$status,$priority,$assignedAdminId?:null,$id]);
        if($s->rowCount()===0){$q=$this->db->prepare('SELECT 1 FROM support_tickets WHERE id=?');$q->execute([$id]);if(!$q->fetchColumn())throw new \InvalidArgumentException('Support ticket not found.');}
    }

    public function reply(int $id,int $adminId,string $body): array
    {
        $body=trim($body);if(mb_strlen($body)<2 || mb_strlen($body)>5000) throw new \InvalidArgumentException('Reply must be between 2 and 5000 characters.');
        $this->db->beginTransaction();
        try{
            $m=$this->db->prepare("INSERT INTO support_messages(ticket_id,author_type,admin_id,body) VALUES(?,'admin',?,?)");$m->execute([$id,$adminId,$body]);
            $u=$this->db->prepare("UPDATE support_tickets SET status='waiting_customer',assigned_admin_id=COALESCE(assigned_admin_id,?),last_admin_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $u->execute([$adminId,$id]);if($u->rowCount()!==1) throw new \InvalidArgumentException('Support ticket not found.');
            $this->db->commit();return $this->ticket($id);
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function forUser(int $userId): array
    {
        $s=$this->db->prepare('SELECT * FROM support_tickets WHERE user_id=? ORDER BY updated_at DESC');$s->execute([$userId]);return $s->fetchAll();
    }
}
