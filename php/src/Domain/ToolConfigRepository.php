<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Domain;

use PDO;

/**
 * Storage for the tool catalog config. One row per tool id.
 *
 * The tool *list* is owned by the frontend (the composed `tds-tool-*` packs); it
 * flows into this table via {@see upsertRegistry()} (the site's build-time
 * registry sync). The admin then edits the *overrides* (enabled / requires-login
 * / premium / price / order); the public catalog reads them back. A registry
 * sync never clobbers an admin override — it only inserts missing rows (with the
 * manifest defaults) and refreshes the display name/category.
 */
final class ToolConfigRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Upsert the known tools from a registry sync. Preserves admin overrides on
     * an existing row; seeds defaults on a new one.
     *
     * @param array<int,array{id:string,name:string,category:string,requires_login_default?:bool,premium_default?:bool,price_cents_default?:int}> $tools
     */
    public function upsertRegistry(array $tools): int
    {
        $sql = 'INSERT INTO tools_config
                    (tool_id, name, category, enabled, requires_login, is_premium, price_cents, sort_order)
                VALUES (:tid, :name, :cat, 1, :rl, :prem, :price, :sort)
                ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category)';
        $stmt = $this->pdo->prepare($sql);
        $n = 0;
        foreach ($tools as $i => $t) {
            $id = trim((string) ($t['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $stmt->execute([
                ':tid' => $id,
                ':name' => mb_substr((string) ($t['name'] ?? $id), 0, 200),
                ':cat' => mb_substr((string) ($t['category'] ?? 'other'), 0, 40),
                ':rl' => !empty($t['requires_login_default']) ? 1 : 0,
                ':prem' => !empty($t['premium_default']) ? 1 : 0,
                ':price' => max(0, (int) ($t['price_cents_default'] ?? 0)),
                ':sort' => (int) $i,
            ]);
            $n++;
        }
        return $n;
    }

    /** Full rows for the admin management UI, ordered for display. */
    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT tool_id, name, category, enabled, requires_login, is_premium, price_cents, sort_order
             FROM tools_config ORDER BY sort_order ASC, name ASC',
        );
        return array_map([self::class, 'shape'], $stmt->fetchAll());
    }

    /**
     * The public catalog rows the static site merges onto its manifest defaults:
     * only the override flags keyed by tool id.
     *
     * @return array<int,array{id:string,enabled:bool,requires_login:bool,is_premium:bool,price_cents:int}>
     */
    public function publicCatalog(): array
    {
        $stmt = $this->pdo->query('SELECT tool_id, enabled, requires_login, is_premium, price_cents FROM tools_config');
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id' => (string) $r['tool_id'],
                'enabled' => (bool) $r['enabled'],
                'requires_login' => (bool) $r['requires_login'],
                'is_premium' => (bool) $r['is_premium'],
                'price_cents' => (int) $r['price_cents'],
            ];
        }
        return $out;
    }

    /** A single tool's config (or null). */
    public function find(string $toolId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT tool_id, name, category, enabled, requires_login, is_premium, price_cents, sort_order
             FROM tools_config WHERE tool_id = :tid',
        );
        $stmt->execute([':tid' => $toolId]);
        $row = $stmt->fetch();
        return $row === false ? null : self::shape($row);
    }

    /**
     * Update the admin-editable override fields on one tool. Only the keys present
     * in $fields are written. @return bool whether a row was updated.
     */
    public function updateOverride(string $toolId, array $fields): bool
    {
        $allowed = ['enabled', 'requires_login', 'is_premium', 'price_cents', 'sort_order'];
        $set = [];
        $params = [':tid' => $toolId];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $set[] = "$key = :$key";
            $params[":$key"] = $key === 'price_cents' || $key === 'sort_order'
                ? max(0, (int) $fields[$key])
                : ((bool) $fields[$key] ? 1 : 0);
        }
        if ($set === []) {
            return false;
        }
        $stmt = $this->pdo->prepare('UPDATE tools_config SET ' . implode(', ', $set) . ' WHERE tool_id = :tid');
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /** Aggregate counts for the dashboard widget. @return array{total:int,enabled:int,premium:int} */
    public function counts(): array
    {
        $row = $this->pdo->query(
            'SELECT COUNT(*) AS total,
                    SUM(enabled = 1) AS enabled,
                    SUM(is_premium = 1) AS premium
             FROM tools_config',
        )->fetch();
        return [
            'total' => (int) ($row['total'] ?? 0),
            'enabled' => (int) ($row['enabled'] ?? 0),
            'premium' => (int) ($row['premium'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $r */
    private static function shape(array $r): array
    {
        return [
            'tool_id' => (string) $r['tool_id'],
            'name' => (string) $r['name'],
            'category' => (string) $r['category'],
            'enabled' => (bool) $r['enabled'],
            'requires_login' => (bool) $r['requires_login'],
            'is_premium' => (bool) $r['is_premium'],
            'price_cents' => (int) $r['price_cents'],
            'sort_order' => (int) $r['sort_order'],
        ];
    }
}
