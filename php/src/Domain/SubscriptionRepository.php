<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Domain;

use PDO;

/**
 * Premium subscriptions (`tools_subscription`), one row per Stripe
 * subscription. Written only by the signed webhook; read by every premium
 * check. Portable SQL only (the route tests run on SQLite).
 */
final class SubscriptionRepository
{
    /**
     * Stripe statuses that keep the tools open. `past_due` included: Stripe
     * retries a failed card for days, and locking a paying customer out on the
     * first decline is worse than a few days' grace. Stripe ends the
     * subscription (`canceled` / `unpaid`) when the retries are exhausted.
     */
    public const GRANTING = ['active', 'trialing', 'past_due'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Insert or update by Stripe subscription id. Fields left null keep their
     * stored value, so a `checkout.session.completed` without a period end
     * never wipes one a subscription event already wrote.
     */
    public function upsert(
        string $stripeSubscriptionId,
        int $userId,
        int $tierId,
        ?string $customerId,
        ?string $status,
        ?string $periodEnd,
        ?bool $cancelAtPeriodEnd,
    ): void {
        $existing = $this->findByStripeId($stripeSubscriptionId);
        if ($existing === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO tools_subscription (app_user_id, tier_id, stripe_subscription_id, stripe_customer_id, status, current_period_end, cancel_at_period_end)
                 VALUES (:uid, :tier, :sid, :cid, :status, :end, :cancel)',
            );
            $stmt->execute([
                ':uid' => $userId,
                ':tier' => $tierId,
                ':sid' => $stripeSubscriptionId,
                ':cid' => $customerId,
                ':status' => $status ?? 'incomplete',
                ':end' => $periodEnd,
                ':cancel' => $cancelAtPeriodEnd ? 1 : 0,
            ]);
            return;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE tools_subscription SET
                stripe_customer_id = COALESCE(:cid, stripe_customer_id),
                status = COALESCE(:status, status),
                current_period_end = COALESCE(:end, current_period_end),
                cancel_at_period_end = COALESCE(:cancel, cancel_at_period_end),
                updated_at = CURRENT_TIMESTAMP
             WHERE stripe_subscription_id = :sid',
        );
        $stmt->execute([
            ':sid' => $stripeSubscriptionId,
            ':cid' => $customerId,
            ':status' => $status,
            ':end' => $periodEnd,
            ':cancel' => $cancelAtPeriodEnd === null ? null : ($cancelAtPeriodEnd ? 1 : 0),
        ]);
    }

    public function findByStripeId(string $stripeSubscriptionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tools_subscription WHERE stripe_subscription_id = :sid');
        $stmt->execute([':sid' => $stripeSubscriptionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::shape($row) : null;
    }

    /** The user's subscriptions that currently grant access, newest first. */
    public function grantingFor(int $userId): array
    {
        $in = implode(',', array_map(static fn (string $s): string => "'{$s}'", self::GRANTING));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM tools_subscription WHERE app_user_id = :uid AND status IN ({$in}) ORDER BY id DESC",
        );
        $stmt->execute([':uid' => $userId]);
        return array_map([self::class, 'shape'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** The newest Stripe customer id of the user, so a second checkout reuses it. */
    public function customerFor(int $userId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT stripe_customer_id FROM tools_subscription WHERE app_user_id = :uid AND stripe_customer_id IS NOT NULL ORDER BY id DESC LIMIT 1',
        );
        $stmt->execute([':uid' => $userId]);
        $id = $stmt->fetchColumn();
        return $id === false || $id === null ? null : (string) $id;
    }

    /** @return array<int,int> tier id => subscriptions currently granting access */
    public function grantingCountsByTier(): array
    {
        $in = implode(',', array_map(static fn (string $s): string => "'{$s}'", self::GRANTING));
        $out = [];
        foreach ($this->pdo->query("SELECT tier_id, COUNT(*) AS n FROM tools_subscription WHERE status IN ({$in}) GROUP BY tier_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['tier_id']] = (int) $r['n'];
        }
        return $out;
    }

    public function countForTier(int $tierId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM tools_subscription WHERE tier_id = :tier');
        $stmt->execute([':tier' => $tierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $r */
    private static function shape(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'app_user_id' => (int) $r['app_user_id'],
            'tier_id' => (int) $r['tier_id'],
            'stripe_subscription_id' => (string) $r['stripe_subscription_id'],
            'stripe_customer_id' => $r['stripe_customer_id'] !== null ? (string) $r['stripe_customer_id'] : null,
            'status' => (string) $r['status'],
            'current_period_end' => $r['current_period_end'] !== null ? (string) $r['current_period_end'] : null,
            'cancel_at_period_end' => (bool) $r['cancel_at_period_end'],
        ];
    }
}
