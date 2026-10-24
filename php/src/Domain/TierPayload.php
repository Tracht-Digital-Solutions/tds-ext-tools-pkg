<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Domain;

/**
 * Validates an admin's stage payload. The price arrives in CENTS (the island
 * converts the euro field) and must be a whole, positive amount: a stage at
 * 0 € would sell a subscription Stripe refuses to create.
 */
final class TierPayload
{
    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>|string the normalised stage, or an error message
     */
    public static function parse(array $body): array|string
    {
        $name = self::text($body['name'] ?? '', 120);
        if ($name === '') {
            return 'Bitte einen Namen angeben.';
        }
        $slug = strtolower(trim((string) ($body['slug'] ?? '')));
        if ($slug === '') {
            $slug = self::slugify($name);
        }
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,58}[a-z0-9])?$/', $slug) !== 1) {
            return 'Der Kurzname darf nur a–z, 0–9 und Bindestriche enthalten.';
        }
        $price = $body['price_cents'] ?? null;
        if (!is_int($price) && !(is_string($price) && ctype_digit($price))) {
            return 'Bitte einen Monatspreis angeben.';
        }
        $price = (int) $price;
        if ($price < 50 || $price > 1_000_000) {
            return 'Der Monatspreis muss zwischen 0,50 € und 10.000 € liegen.';
        }
        $tools = $body['tool_ids'] ?? [];
        if (!is_array($tools)) {
            return 'Ungültige Werkzeugliste.';
        }
        $tools = array_values(array_unique(array_filter(array_map(
            static fn ($t): string => trim((string) $t),
            $tools,
        ), static fn (string $t): bool => preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $t) === 1)));
        if ($tools === []) {
            return 'Eine Stufe muss mindestens ein Werkzeug freischalten.';
        }

        return [
            'slug' => $slug,
            'name' => $name,
            'name_en' => self::nullable(self::text($body['name_en'] ?? '', 120)),
            'tagline' => self::nullable(self::text($body['tagline'] ?? '', 255)),
            'tagline_en' => self::nullable(self::text($body['tagline_en'] ?? '', 255)),
            'features' => self::lines($body['features'] ?? []),
            'features_en' => self::lines($body['features_en'] ?? []),
            'price_cents' => $price,
            'tool_ids' => $tools,
            'active' => !array_key_exists('active', $body) || (bool) $body['active'],
            'highlight' => (bool) ($body['highlight'] ?? false),
            'sort_order' => max(0, (int) ($body['sort_order'] ?? 0)),
        ];
    }

    private static function text(mixed $v, int $max): string
    {
        $s = preg_replace('/[\x00-\x1f\x7f]/u', '', trim((string) $v)) ?? '';
        return mb_substr($s, 0, $max);
    }

    private static function nullable(string $s): ?string
    {
        return $s === '' ? null : $s;
    }

    /** @return list<string> at most 12 lines of 160 characters */
    private static function lines(mixed $v): array
    {
        $list = is_array($v) ? $v : explode("\n", (string) $v);
        $out = [];
        foreach ($list as $line) {
            $line = self::text($line, 160);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return array_slice($out, 0, 12);
    }

    private static function slugify(string $name): string
    {
        $map = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue'];
        $s = strtolower(strtr($name, $map));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim(substr(trim($s, '-'), 0, 60), '-');
    }
}
