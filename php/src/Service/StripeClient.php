<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Service;

/**
 * Thin Stripe API client (plain ext-curl, no SDK — the extension convention).
 * Covers the ONE flow the tools paywall needs: a Checkout Session for a premium
 * tool (one-time payment). Unlike tds-ext-billing (hosted invoices), a paywall
 * wants Stripe Checkout — the user pays inline and `checkout.session.completed`
 * (signed webhook) grants the entitlement.
 *
 * The live call can't be exercised without a Stripe account; the signed-webhook
 * path ({@see WebhookVerifier}) is the unit-tested part.
 *
 * @see https://stripe.com/docs/api/checkout/sessions/create
 */
final class StripeClient
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $baseUrl = 'https://api.stripe.com/v1',
    ) {
    }

    /** False when no secret key is configured — the paywall is then disabled (503). */
    public function isConfigured(): bool
    {
        return $this->secretKey !== '';
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
        $session = $this->post('/checkout/sessions', [
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
     * POST a form-encoded request (Stripe accepts nested params as
     * `a[b][c]=…`, which http_build_query emits). Returns the decoded body on
     * 2xx, throws otherwise.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     * @throws StripeException
     */
    private function post(string $path, array $params): array
    {
        $ch = curl_init($this->baseUrl . $path);
        if ($ch === false) {
            throw new StripeException('Stripe-Anfrage konnte nicht initialisiert werden.', 0);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params, '', '&'),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->secretKey,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new StripeException('Stripe nicht erreichbar: ' . $err, 0);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $body = json_decode((string) $raw, true);
        $body = is_array($body) ? $body : [];
        if ($status < 200 || $status >= 300) {
            $msg = isset($body['error']['message']) && is_string($body['error']['message'])
                ? $body['error']['message']
                : 'HTTP ' . $status;
            throw new StripeException('Stripe: ' . $msg, $status);
        }
        return $body;
    }
}
