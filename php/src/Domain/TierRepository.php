<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Domain;

use PDO;

/**
 * The premium stages (`tools_tier`), managed freely in the admin panel.
 *
 * A stage is a name, a fixed monthly price, the advertising copy in both
 * languages and the list of tools it unlocks. Portable SQL only (the route
 * tests run on SQLite).
 */
final class TierRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> every stage, for the admin */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM tools_tier ORDER BY sort_order ASC, price_cents ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
        return array_map([self::class, 'shape'], $rows);
    }

    /** @return list<array<string,mixed>> the stages on sale, cheapest first within the admin's order */
    public function active(): array
    {
        $rows = $this->pdo->query('SELECT * FROM tools_tier WHERE active = 1 ORDER BY sort_order ASC, price_cents ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
        return array_map([self::class, 'shape'], $rows);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tools_tier WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::shape($row) : null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tools_tier WHERE slug = :slug');
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::shape($row) : null;
    }

    /** The cheapest stage on sale that unlocks the tool, or null. */
    public function cheapestFor(string $toolId): ?array
    {
        $best = null;
        foreach ($this->active() as $tier) {
            if (in_array($toolId, $tier['tool_ids'], true) && ($best === null || $tier['price_cents'] < $best['price_cents'])) {
                $best = $tier;
            }
        }
        return $best;
    }

    /** @param array<string,mixed> $t a payload from {@see TierPayload::parse()} */
    public function create(array $t): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tools_tier (slug, name, name_en, tagline, tagline_en, features, features_en, price_cents, tool_ids, active, highlight, sort_order)
             VALUES (:slug, :name, :name_en, :tagline, :tagline_en, :features, :features_en, :price, :tools, :active, :highlight, :sort)',
        );
        $stmt->execute(self::params($t));
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $t */
    public function update(int $id, array $t): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tools_tier SET slug = :slug, name = :name, name_en = :name_en, tagline = :tagline, tagline_en = :tagline_en,
                features = :features, features_en = :features_en, price_cents = :price, tool_ids = :tools,
                active = :active, highlight = :highlight, sort_order = :sort
             WHERE id = :id',
        );
        $stmt->execute(self::params($t) + [':id' => $id]);
        return $stmt->rowCount() > 0 || $this->find($id) !== null;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM tools_tier WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function slugTaken(string $slug, ?int $exceptId = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM tools_tier WHERE slug = :slug');
        $stmt->execute([':slug' => $slug]);
        $id = $stmt->fetchColumn();
        return $id !== false && (int) $id !== $exceptId;
    }

    /** @param array<string,mixed> $t */
    private static function params(array $t): array
    {
        return [
            ':slug' => $t['slug'],
            ':name' => $t['name'],
            ':name_en' => $t['name_en'],
            ':tagline' => $t['tagline'],
            ':tagline_en' => $t['tagline_en'],
            ':features' => implode("\n", $t['features']),
            ':features_en' => implode("\n", $t['features_en']),
            ':price' => $t['price_cents'],
            ':tools' => json_encode(array_values($t['tool_ids'])),
            ':active' => $t['active'] ? 1 : 0,
            ':highlight' => $t['highlight'] ? 1 : 0,
            ':sort' => $t['sort_order'],
        ];
    }

    /** @param array<string,mixed> $r */
    private static function shape(array $r): array
    {
        $tools = json_decode((string) ($r['tool_ids'] ?? '[]'), true);
        $lines = static fn ($v): array => array_values(array_filter(array_map('trim', explode("\n", (string) $v)), static fn (string $l): bool => $l !== ''));
        return [
            'id' => (int) $r['id'],
            'slug' => (string) $r['slug'],
            'name' => (string) $r['name'],
            'name_en' => $r['name_en'] !== null && $r['name_en'] !== '' ? (string) $r['name_en'] : null,
            'tagline' => $r['tagline'] !== null && $r['tagline'] !== '' ? (string) $r['tagline'] : null,
            'tagline_en' => $r['tagline_en'] !== null && $r['tagline_en'] !== '' ? (string) $r['tagline_en'] : null,
            'features' => $lines($r['features'] ?? ''),
            'features_en' => $lines($r['features_en'] ?? ''),
            'price_cents' => (int) $r['price_cents'],
            'tool_ids' => is_array($tools) ? array_values(array_filter($tools, 'is_string')) : [],
            'active' => (bool) $r['active'],
            'highlight' => (bool) $r['highlight'],
            'sort_order' => (int) $r['sort_order'],
        ];
    }
}
