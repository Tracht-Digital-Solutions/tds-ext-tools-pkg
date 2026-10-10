<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Studio;

/**
 * Which Designsystem-Studio export formats are free and which need Premium.
 *
 * Stored as ONE setting (`studio_premium_formats`, a comma list of premium
 * formats) so the admin changes the split without a release. The default must
 * equal `DEFAULT_PREMIUM` in tds-tool-devkit-pkg's `src/studio/policy.ts`: the
 * site's export endpoint falls back to it when this API cannot be reached.
 */
final class StudioPolicy
{
    /** The tool id the studio's premium entitlement is stored under. */
    public const TOOL_ID = 'design-system-studio';

    public const FORMATS = ['css', 'variables', 'html', 'scss', 'json', 'tailwind', 'bootstrap', 'zip'];

    public const DEFAULT_PREMIUM = 'scss,tailwind,bootstrap,zip';

    /** @return array<string,'free'|'premium'> */
    public static function formats(string $premiumSetting): array
    {
        $premium = array_filter(array_map(
            static fn (string $f): string => strtolower(trim($f)),
            explode(',', $premiumSetting),
        ));
        $out = [];
        foreach (self::FORMATS as $f) {
            $out[$f] = in_array($f, $premium, true) ? 'premium' : 'free';
        }
        return $out;
    }

    /** Normalises an admin-supplied list to known formats, in canonical order. */
    public static function normalize(string $premiumSetting): string
    {
        return implode(',', array_keys(array_filter(
            self::formats($premiumSetting),
            static fn (string $tier): bool => $tier === 'premium',
        )));
    }
}
