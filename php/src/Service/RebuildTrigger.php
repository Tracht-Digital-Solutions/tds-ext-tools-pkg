<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Service;

/**
 * Best-effort GitHub `workflow_dispatch` trigger — fires a rebuild of the public
 * tools site (`tds-tools`) after an admin changes the catalog config, so the
 * static site re-bakes with the new enabled/premium/ads state.
 *
 * Config (repo / workflow / token) comes from the core SettingsStore (ns=tools)
 * with env fallback. MUST NOT throw — a flaky GitHub API or a missing token must
 * never fail the admin's save; failures are logged. Plain ext-curl, no Guzzle
 * (mirrors blog-cms' RebuildTrigger).
 *
 * @see https://docs.github.com/en/rest/actions/workflows#create-a-workflow-dispatch-event
 */
final class RebuildTrigger
{
    public function __construct(
        /** GitHub PAT with `repo`/`workflow` scope; empty ⇒ no-op. */
        private readonly string $token,
        private readonly string $ref = 'main',
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    /**
     * Dispatch `repo`'s `workflowFile`. `repo` is "owner/name"; `workflowFile` the
     * workflow file name (default "dev.yml"). No-op when unconfigured or no repo.
     */
    public function trigger(?string $repo, ?string $workflowFile, string $reason): void
    {
        $repo = trim((string) $repo);
        $workflowFile = trim((string) ($workflowFile ?: 'dev.yml'));
        if ($this->token === '' || $repo === '') {
            return;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/actions/workflows/%s/dispatches',
            $repo,
            rawurlencode($workflowFile),
        );
        // Send `ref` only: the dispatches endpoint 422s on undeclared inputs
        // (dev.yml/release.yml declare none). Reason is logged only.
        $payload = json_encode(['ref' => $this->ref], JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        if ($ch === false) {
            return;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
                'Content-Type: application/json',
                'User-Agent: tds-ext-tools',
            ],
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        // GitHub returns 204 No Content on success.
        if ($ok === false || $code >= 300) {
            error_log(sprintf('[tools] rebuild trigger failed (%s → %s, HTTP %d): %s', $reason, $repo, $code, $err));
        }
    }
}
