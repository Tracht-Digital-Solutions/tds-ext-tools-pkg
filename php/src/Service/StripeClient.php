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

}
