<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Domain;

use PDO;

/**
 * Premium entitlements — "has this logged-in user paid for this tool?".
 *
 * Premium tools require login (the entitlement is bound to the app_user id, not
 * an anonymous token), so one row per (user, tool). A row is granted by the
 * Stripe `checkout.session.completed` webhook and checked by the tool page's
 * gate. `expires_at IS NULL` = perpetual (one-time purchase).
 */
final class EntitlementRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Grant (or re-activate) an entitlement. Idempotent on (app_user_id, tool_id). */
    public function grant(int $userId, string $toolId, ?string $sourceStripeId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tools_entitlement (app_user_id, tool_id, status, source_stripe_id)
             VALUES (:uid, :tid, "active", :src)
             ON DUPLICATE KEY UPDATE status = "active", source_stripe_id = VALUES(source_stripe_id)',
        );
        $stmt->execute([':uid' => $userId, ':tid' => $toolId, ':src' => $sourceStripeId]);
    }

    /** True when the user holds an active, unexpired entitlement for the tool. */
    public function isEntitled(int $userId, string $toolId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM tools_entitlement
             WHERE app_user_id = :uid AND tool_id = :tid AND status = "active"
               AND (expires_at IS NULL OR expires_at > NOW())
             LIMIT 1',
        );
        $stmt->execute([':uid' => $userId, ':tid' => $toolId]);
        return $stmt->fetchColumn() !== false;
    }
}
