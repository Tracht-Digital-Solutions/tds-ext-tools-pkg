<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Service;

use Tds\Ext\Tools\Domain\EntitlementRepository;
use Tds\Ext\Tools\Domain\SubscriptionRepository;
use Tds\Ext\Tools\Domain\TierRepository;
use Throwable;

/**
 * The one answer to "may this user use this premium tool?":
 *
 *  - an admin always may;
 *  - a one-off purchase (`tools_entitlement`) stays valid forever — selling
 *    them ended with the subscriptions, honouring them did not;
 *  - otherwise a subscription Stripe still counts as paying
 *    ({@see SubscriptionRepository::GRANTING}) whose stage lists the tool.
 *
 * Fails closed: a missing table (before the migration ran) or a database
 * error means "no subscription", never "open".
 */
final class PremiumAccess
{
    public function __construct(
        private readonly EntitlementRepository $entitlements,
        private readonly SubscriptionRepository $subscriptions,
        private readonly TierRepository $tiers,
    ) {
    }

    public function isEntitled(int $userId, string $toolId, bool $isAdmin = false): bool
    {
        if ($isAdmin) {
            return true;
        }
        try {
            if ($this->entitlements->isEntitled($userId, $toolId)) {
                return true;
            }
        } catch (Throwable) {
        }
        return in_array($toolId, $this->subscribedTools($userId), true);
    }

    /** @return list<string> the tool ids the user's granting subscriptions unlock */
    public function subscribedTools(int $userId): array
    {
        try {
            $tools = [];
            foreach ($this->subscriptions->grantingFor($userId) as $sub) {
                $tier = $this->tiers->find($sub['tier_id']);
                foreach ($tier['tool_ids'] ?? [] as $id) {
                    $tools[$id] = true;
                }
            }
            return array_keys($tools);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The visitor's premium state for the site (`GET /tools/premium/status`).
     *
     * @return array{subscriptions:list<array<string,mixed>>, tools:list<string>}
     */
    public function status(int $userId): array
    {
        $subs = [];
        try {
            foreach ($this->subscriptions->grantingFor($userId) as $sub) {
                $tier = $this->tiers->find($sub['tier_id']);
                $subs[] = [
                    'tier' => $tier['slug'] ?? null,
                    'name' => $tier['name'] ?? null,
                    'name_en' => $tier['name_en'] ?? null,
                    'status' => $sub['status'],
                    // Written as UTC by the webhook (gmdate); handed out as ISO 8601 with Z.
                    'current_period_end' => $sub['current_period_end'] !== null ? str_replace(' ', 'T', $sub['current_period_end']) . 'Z' : null,
                    'cancel_at_period_end' => $sub['cancel_at_period_end'],
                ];
            }
        } catch (Throwable) {
            $subs = [];
        }
        return ['subscriptions' => $subs, 'tools' => $this->subscribedTools($userId)];
    }
}
