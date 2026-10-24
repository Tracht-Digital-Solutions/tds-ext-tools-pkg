<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Service;

/**
 * Turns a verified Stripe event into the subscription row it describes, or
 * null when the event is not about a premium subscription of this module.
 *
 * Two event families carry it:
 *  - `checkout.session.completed` in `mode=subscription`: the first sight of
 *    the subscription. User and stage come from the session metadata. Its
 *    status is NOT taken from the session — `complete` says the form was
 *    finished, not that the first invoice is paid; the subscription events
 *    (sent right after) carry the real status.
 *  - `customer.subscription.created|updated|deleted`: status, period end and
 *    cancellation, with user and stage in the subscription's own metadata
 *    (copied there via `subscription_data.metadata` at checkout).
 *
 * The period end moved from the subscription to its items in newer Stripe
 * API versions; both places are read.
 */
final class SubscriptionEvent
{
    /**
     * @param array<string,mixed> $event the decoded webhook payload
     * @return array{subscriptionId:string, userId:int, tierId:int, customerId:?string, status:?string, periodEnd:?string, cancelAtPeriodEnd:?bool}|null
     */
    public static function fromStripe(array $event): ?array
    {
        $type = (string) ($event['type'] ?? '');
        $object = $event['data']['object'] ?? null;
        if (!is_array($object)) {
            return null;
        }

        if ($type === 'checkout.session.completed') {
            if (($object['mode'] ?? null) !== 'subscription') {
                return null;
            }
            $meta = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
            $sub = $object['subscription'] ?? null;
            $subId = is_array($sub) ? (string) ($sub['id'] ?? '') : (string) ($sub ?? '');
            $userId = (int) ($object['client_reference_id'] ?? ($meta['user_id'] ?? 0));
            $tierId = (int) ($meta['tier_id'] ?? 0);
            if ($subId === '' || $userId <= 0 || $tierId <= 0) {
                return null;
            }
            return [
                'subscriptionId' => $subId,
                'userId' => $userId,
                'tierId' => $tierId,
                'customerId' => self::id($object['customer'] ?? null),
                'status' => null,
                'periodEnd' => null,
                'cancelAtPeriodEnd' => null,
            ];
        }

        if (!in_array($type, ['customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted'], true)) {
            return null;
        }
        $meta = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $subId = (string) ($object['id'] ?? '');
        $userId = (int) ($meta['user_id'] ?? 0);
        $tierId = (int) ($meta['tier_id'] ?? 0);
        // Not ours: another module's subscription on the shared Stripe account.
        if ($subId === '' || ($meta['module'] ?? null) !== 'tools' || $userId <= 0 || $tierId <= 0) {
            return null;
        }
        $end = $object['current_period_end'] ?? ($object['items']['data'][0]['current_period_end'] ?? null);
        return [
            'subscriptionId' => $subId,
            'userId' => $userId,
            'tierId' => $tierId,
            'customerId' => self::id($object['customer'] ?? null),
            'status' => $type === 'customer.subscription.deleted' ? 'canceled' : (string) ($object['status'] ?? 'incomplete'),
            'periodEnd' => is_numeric($end) ? gmdate('Y-m-d H:i:s', (int) $end) : null,
            'cancelAtPeriodEnd' => (bool) ($object['cancel_at_period_end'] ?? false),
        ];
    }

    private static function id(mixed $v): ?string
    {
        if (is_array($v)) {
            $v = $v['id'] ?? null;
        }
        $v = is_string($v) ? trim($v) : '';
        return $v === '' ? null : $v;
    }
}
