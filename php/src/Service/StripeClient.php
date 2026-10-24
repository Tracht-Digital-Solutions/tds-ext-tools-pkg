<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Service;

use Tds\Frontend\Contract\Stripe\StripeApi;
use Tds\Frontend\Contract\Stripe\StripeException;

/**
 * Thin Stripe API client (plain ext-curl, no SDK — the extension convention).
 * Covers the ONE flow the tools paywall needs: a Checkout Session for a premium
 * tool (one-time payment). Unlike tds-ext-billing (hosted invoices), a paywall
 * wants Stripe Checkout — the user pays inline and `checkout.session.completed`
 * (signed webhook) grants the entitlement.
 *
 * The live call can't be exercised without a Stripe account; the signed-webhook
 * path ({@see \Tds\Frontend\Contract\Stripe\StripeWebhook}) is the unit-tested part.
 *
 * @see https://stripe.com/docs/api/checkout/sessions/create
 */
final class StripeClient
{
    /**
     * The transport is the platform's StripeApi (tds-frontend-contract):
     * the central account from Einstellungen → Zahlungen, or a module key
     * that overrides it. This class keeps only the domain call.
     */
    public function __construct(private readonly StripeApi $api)
    {
    }

    /** False when no secret key is configured — the paywall is then disabled (503). */
    public function isConfigured(): bool
    {
        return $this->api->isConfigured();
    }

    /**
     * Create a one-time-payment Checkout Session for a premium tool. The
     * entitlement is keyed by `client_reference_id` (the app_user id) + the
     * `tool_id` metadata, both read back from the webhook.
     *
     * @return array{id:string,url:?string}
     * @throws StripeException
     */
    public function createCheckoutSession(
        int $userId,
        string $toolId,
        string $toolName,
        int $priceCents,
        string $currency,
        string $successUrl,
        string $cancelUrl,
    ): array {
        $session = $this->api->post('/checkout/sessions', [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $userId,
            'metadata' => ['tool_id' => $toolId, 'user_id' => (string) $userId],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($currency),
                    'unit_amount' => $priceCents,
                    'product_data' => ['name' => $toolName],
                ],
            ]],
        ]);
        return [
            'id' => (string) ($session['id'] ?? ''),
            'url' => isset($session['url']) ? (string) $session['url'] : null,
        ];
    }

    /**
     * A monthly subscription Checkout Session for a premium stage. The price
     * is sent inline (`price_data` with `recurring`), so a stage needs no
     * product set up in Stripe; a later price change applies to new
     * subscriptions only. User and stage ride in the session metadata AND in
     * `subscription_data.metadata`, so every later subscription event maps
     * back without a lookup (`module=tools` marks them as ours on the shared
     * account).
     *
     * @return array{id:string,url:?string}
     * @throws StripeException
     */
    public function createSubscriptionCheckout(
        int $userId,
        int $tierId,
        string $tierName,
        int $priceCents,
        string $currency,
        ?string $customerId,
        ?string $email,
        string $successUrl,
        string $cancelUrl,
    ): array {
        $meta = ['module' => 'tools', 'user_id' => (string) $userId, 'tier_id' => (string) $tierId];
        $params = [
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $userId,
            'metadata' => $meta,
            'subscription_data' => ['metadata' => $meta],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($currency),
                    'unit_amount' => $priceCents,
                    'recurring' => ['interval' => 'month'],
                    'product_data' => ['name' => $tierName],
                ],
            ]],
        ];
        if ($customerId !== null && $customerId !== '') {
            $params['customer'] = $customerId;
        } elseif ($email !== null && $email !== '') {
            $params['customer_email'] = $email;
        }
        $session = $this->api->post('/checkout/sessions', $params);
        return [
            'id' => (string) ($session['id'] ?? ''),
            'url' => isset($session['url']) ? (string) $session['url'] : null,
        ];
    }

    /**
     * A Stripe customer portal session: cancel, change the card, download
     * invoices. Cancelling there is the cancellation button a monthly
     * contract needs. The portal must be activated once in the Stripe
     * dashboard.
     *
     * @throws StripeException
     */
    public function createPortalSession(string $customerId, string $returnUrl): ?string
    {
        $session = $this->api->post('/billing_portal/sessions', [
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);
        return isset($session['url']) ? (string) $session['url'] : null;
    }
}
