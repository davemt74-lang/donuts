<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class ReviewService
{
    public function __construct(private readonly PDO $db) {}

    public function eligibleFlavorIds(int $userId): array
    {
        $s=$this->db->prepare("SELECT oi.configuration_json FROM order_items oi JOIN orders o ON o.id=oi.order_id
          WHERE o.user_id=? AND o.status IN ('paid','preparing','ready','shipped','delivered','completed','refunded')");
        $s->execute([$userId]);$ids=[];
        foreach($s->fetchAll() as $row){
            $box=json_decode((string)$row['configuration_json'],true);if(!is_array($box))continue;
            foreach($box['items']??[] as $item){$id=(int)($item['flavor_id']??0);if($id>0)$ids[$id]=true;}
        }
        return array_map('intval',array_keys($ids));
    }

    public function canReview(int $userId,int $flavorId): bool
    {
        return in_array($flavorId,$this->eligibleFlavorIds($userId),true);
    }

    public function submit(int $userId,int $flavorId,int $rating,string $title,string $body): int
    {
        if(!$this->canReview($userId,$flavorId)) throw new \InvalidArgumentException('Only verified purchasers can review this flavor.');
        if($rating<1||$rating>5) throw new \InvalidArgumentException('Rating must be between 1 and 5.');
        $title=trim($title);$body=trim($body);
        if(mb_strlen($title)>190) throw new \InvalidArgumentException('Review title is too long.');
        if(mb_strlen($body)<10 || mb_strlen($body)>3000) throw new \InvalidArgumentException('Review must be between 10 and 3000 characters.');
        $s=$this->db->prepare("INSERT INTO product_reviews(user_id,flavor_id,rating,title,body,status,verified_purchase) VALUES(?,?,?,?,?,'pending',1)
          ON CONFLICT(user_id,flavor_id) DO UPDATE SET rating=excluded.rating,title=excluded.title,body=excluded.body,status='pending',verified_purchase=1,moderated_by=NULL,moderated_at=NULL,updated_at=CURRENT_TIMESTAMP");
        $s->execute([$userId,$flavorId,$rating,$title,$body]);
        $q=$this->db->prepare('SELECT id FROM product_reviews WHERE user_id=? AND flavor_id=?');$q->execute([$userId,$flavorId]);return (int)$q->fetchColumn();
    }

    public function userReview(int $userId,int $flavorId): ?array
    {
        $s=$this->db->prepare('SELECT * FROM product_reviews WHERE user_id=? AND flavor_id=?');$s->execute([$userId,$flavorId]);return $s->fetch()?:null;
    }

    public function approvedForFlavor(int $flavorId,int $limit=30): array
    {
        $limit=max(1,min(100,$limit));
        $s=$this->db->prepare("SELECT r.rating,r.title,r.body,r.created_at,u.first_name FROM product_reviews r JOIN users u ON u.id=r.user_id WHERE r.flavor_id=? AND r.status='approved' ORDER BY r.created_at DESC LIMIT {$limit}");
        $s->execute([$flavorId]);return $s->fetchAll();
    }

    public function aggregate(int $flavorId): array
    {
        $s=$this->db->prepare("SELECT COUNT(*) count,COALESCE(AVG(rating),0) average FROM product_reviews WHERE flavor_id=? AND status='approved'");
        $s->execute([$flavorId]);$row=$s->fetch()?:['count'=>0,'average'=>0];
        return ['count'=>(int)$row['count'],'average'=>round((float)$row['average'],2)];
    }

    public function queue(?string $status='pending',int $limit=200): array
    {
        $limit=max(1,min(500,$limit));$params=[];$where='';
        if($status!==null&&$status!==''){
            if(!in_array($status,['pending','approved','rejected'],true)) throw new \InvalidArgumentException('Invalid review status.');
            $where='WHERE r.status=?';$params[]=$status;
        }
        $s=$this->db->prepare("SELECT r.*,f.name flavor_name,f.slug flavor_slug,u.first_name,u.last_name,u.email FROM product_reviews r JOIN flavors f ON f.id=r.flavor_id JOIN users u ON u.id=r.user_id {$where} ORDER BY CASE r.status WHEN 'pending' THEN 0 ELSE 1 END,r.created_at DESC LIMIT {$limit}");
        $s->execute($params);return $s->fetchAll();
    }

    public function stats(): array
    {
        $out=['pending'=>0,'approved'=>0,'rejected'=>0];
        foreach($this->db->query('SELECT status,COUNT(*) count FROM product_reviews GROUP BY status')->fetchAll() as $row)$out[(string)$row['status']]=(int)$row['count'];
        return $out;
    }

    public function moderate(int $reviewId,string $status,int $adminId): void
    {
        if(!in_array($status,['approved','rejected'],true)) throw new \InvalidArgumentException('Review can only be approved or rejected.');
        $s=$this->db->prepare('UPDATE product_reviews SET status=?,moderated_by=?,moderated_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $s->execute([$status,$adminId,$reviewId]);
        if($s->rowCount()!==1) throw new \InvalidArgumentException('Review not found.');
    }
}
