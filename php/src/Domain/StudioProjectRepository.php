<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Domain;

use PDO;

/**
 * Saved Designsystem-Studio projects (Premium only — the route checks the
 * entitlement before any method here runs). Every query is scoped to the
 * owner: a project id from another user behaves exactly like a missing one.
 *
 * `data` is the studio's versioned project JSON; the server stores it whole
 * and never edits it, so the studio stays the one place that understands it.
 */
final class StudioProjectRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id:int,name:string,updated_at:string}> */
    public function listFor(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, updated_at FROM tools_studio_project WHERE app_user_id = :uid ORDER BY updated_at DESC, id DESC',
        );
        $stmt->execute([':uid' => $userId]);
        $out = [];
        while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'updated_at' => (string) $r['updated_at']];
        }
        return $out;
    }

    public function countFor(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM tools_studio_project WHERE app_user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{id:int,name:string,data:mixed,updated_at:string}|null */
    public function find(int $userId, int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, data, updated_at FROM tools_studio_project WHERE id = :id AND app_user_id = :uid',
        );
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        return [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'data' => json_decode((string) $r['data'], true),
            'updated_at' => (string) $r['updated_at'],
        ];
    }

    public function create(int $userId, string $name, int $schema, string $json): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tools_studio_project (app_user_id, name, schema_version, data) VALUES (:uid, :name, :schema, :data)',
        );
        $stmt->execute([':uid' => $userId, ':name' => $name, ':schema' => $schema, ':data' => $json]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $userId, int $id, string $name, int $schema, string $json): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tools_studio_project SET name = :name, schema_version = :schema, data = :data, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND app_user_id = :uid',
        );
        $stmt->execute([':name' => $name, ':schema' => $schema, ':data' => $json, ':id' => $id, ':uid' => $userId]);
        if ($stmt->rowCount() > 0) {
            return true;
        }
        // MySQL reports 0 affected rows for an identical save; tell that apart from "not yours".
        return $this->find($userId, $id) !== null;
    }

    public function delete(int $userId, int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM tools_studio_project WHERE id = :id AND app_user_id = :uid');
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array{id:int,name:string,updated_at:string}|null */
    public function summary(int $userId, int $id): ?array
    {
        $p = $this->find($userId, $id);
        return $p === null ? null : ['id' => $p['id'], 'name' => $p['name'], 'updated_at' => $p['updated_at']];
    }
}
