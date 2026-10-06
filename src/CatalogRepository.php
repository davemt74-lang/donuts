<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class CatalogRepository
{
    public function __construct(private readonly PDO $db) {}

    public function packs(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM pack_sizes' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, size';
        return $this->db->query($sql)->fetchAll();
    }

    public function flavors(bool $availableOnly = true): array
    {
        $where = $availableOnly ? ' WHERE active = 1 AND sold_out = 0' : '';
        return $this->db->query('SELECT * FROM flavors' . $where . ' ORDER BY sort_order, name')->fetchAll();
    }

    public function flavorById(int $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM flavors WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public function packBySize(int $size): ?array
    {
        $s = $this->db->prepare('SELECT * FROM pack_sizes WHERE size = ? AND active = 1');
        $s->execute([$size]);
        return $s->fetch() ?: null;
    }

    public function eligibleFlavorIds(int $packId): array
    {
        $s = $this->db->prepare('SELECT flavor_id FROM pack_flavor_eligibility WHERE pack_size_id = ? AND enabled = 1');
        $s->execute([$packId]);
        return array_map('intval', array_column($s->fetchAll(), 'flavor_id'));
    }

    public function saveFlavor(array $data): int
    {
        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));
        if ($name === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
            throw new \InvalidArgumentException('Name and a lowercase URL-safe slug are required.');
        }
        $values = [
            $name, $slug, trim((string)($data['description'] ?? '')),
            max(0, (int)($data['surcharge_cents'] ?? 0)),
            trim((string)($data['image_path'] ?? '')),
            trim((string)($data['ingredients'] ?? '')),
            trim((string)($data['allergens'] ?? '')),
            !empty($data['active']) ? 1 : 0,
            !empty($data['sold_out']) ? 1 : 0,
            !empty($data['seasonal']) ? 1 : 0,
            (int)($data['sort_order'] ?? 0),
        ];
        if ($id > 0) {
            $values[] = $id;
            $s = $this->db->prepare('UPDATE flavors SET name=?,slug=?,description=?,surcharge_cents=?,image_path=?,ingredients=?,allergens=?,active=?,sold_out=?,seasonal=?,sort_order=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $s->execute($values);
            return $id;
        }
        $s = $this->db->prepare('INSERT INTO flavors (name,slug,description,surcharge_cents,image_path,ingredients,allergens,active,sold_out,seasonal,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $s->execute($values);
        return (int)$this->db->lastInsertId();
    }
}
