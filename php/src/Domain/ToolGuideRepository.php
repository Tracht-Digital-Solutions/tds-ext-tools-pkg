<?php
declare(strict_types=1);

namespace Tds\Ext\Tools\Domain;

use PDO;

/**
 * Storage for the panel-editable copy of a tool page: its display name and
 * description, its SEO fields, and the long-form guide (intro, use cases,
 * steps, FAQ, privacy note, related tools).
 *
 * **Everything here is an OVERRIDE.** The tool list, the German manifest copy
 * and the committed guides in `tds-tools-frontend/src/content/guides` remain
 * the source of truth when a row is missing or a field is empty, which is what
 * makes the public site immune to an empty or unreachable database: it renders
 * exactly what it rendered before this table existed. Same contract as the
 * landingpage's content blocks.
 *
 * The list-shaped fields are stored as JSON text. They are read and written
 * whole, never queried into.
 */
final class ToolGuideRepository
{
    /** The fields the panel may write. */
    private const FIELDS = [
        'name',
        'description',
        'seo_title',
        'seo_description',
        'intro',
        'use_cases',
        'steps',
        'faq',
        'related',
        'privacy',
    ];

    /** Which of those hold a JSON list rather than a plain string. */
    private const JSON_FIELDS = ['intro', 'use_cases', 'steps', 'faq', 'related'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Every stored guide for a language, keyed by tool id.
     *
     * The public read: one query for the whole site build rather than one per
     * tool page. Fields that are NULL or empty are omitted entirely, so the
     * consumer's `??` fallback to the committed copy works per FIELD and not
     * only per row — an editor who translated the intro but not the FAQ gets
     * the translated intro and the committed FAQ, rather than an empty FAQ.
     */
    public function allForLang(string $lang): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tools_guide WHERE lang = :lang ORDER BY tool_id'
        );
        $stmt->execute([':lang' => $lang]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['tool_id']] = self::decode($row);
        }
        return $out;
    }

    /** One guide, or null when nothing is stored for that tool + language. */
    public function find(string $toolId, string $lang): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tools_guide WHERE tool_id = :tid AND lang = :lang LIMIT 1'
        );
        $stmt->execute([':tid' => $toolId, ':lang' => $lang]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::decode($row);
    }

    /** Every stored row, for the admin list. */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM tools_guide ORDER BY tool_id, lang');
        return array_map(
            static fn (array $row): array => ['tool_id' => $row['tool_id'], 'lang' => $row['lang']]
                + self::decode($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * Create or replace one tool's copy in one language.
     *
     * A field absent from `$data` is written as NULL, i.e. "fall back to the
     * committed copy" — deliberately, so clearing a field in the panel really
     * clears it. Anything else would make an override impossible to undo
     * without a database client.
     */
    public function save(string $toolId, string $lang, array $data, bool $machineTranslated = false): void
    {
        $columns = ['tool_id', 'lang', 'machine_translated', ...self::FIELDS];
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $updates = array_map(
            static fn (string $c): string => "$c = VALUES($c)",
            ['machine_translated', ...self::FIELDS],
        );

        $sql = 'INSERT INTO tools_guide (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', $placeholders) . ') ON DUPLICATE KEY UPDATE '
            . implode(', ', $updates);

        $params = [
            ':tool_id' => $toolId,
            ':lang' => $lang,
            ':machine_translated' => $machineTranslated ? 1 : 0,
        ];
        foreach (self::FIELDS as $field) {
            $params[':' . $field] = self::encode($field, $data[$field] ?? null);
        }

        $this->pdo->prepare($sql)->execute($params);
    }

    /** Remove one tool's copy in one language. Missing is success. */
    public function delete(string $toolId, string $lang): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM tools_guide WHERE tool_id = :tid AND lang = :lang');
        $stmt->execute([':tid' => $toolId, ':lang' => $lang]);
    }

    /** Turn a stored row into the shape the frontend consumes. */
    private static function decode(array $row): array
    {
        $out = ['machineTranslated' => (bool) ($row['machine_translated'] ?? false)];

        foreach (self::FIELDS as $field) {
            $value = $row[$field] ?? null;
            if ($value === null || $value === '') {
                // Omitted, not null: the consumer falls back per field.
                continue;
            }
            if (in_array($field, self::JSON_FIELDS, true)) {
                $decoded = json_decode((string) $value, true);
                if (!is_array($decoded) || $decoded === []) {
                    continue;
                }
                $out[$field] = $decoded;
                continue;
            }
            $out[$field] = (string) $value;
        }

        return $out;
    }

    /** Normalise one incoming field for storage. */
    private static function encode(string $field, mixed $value): ?string
    {
        if (in_array($field, self::JSON_FIELDS, true)) {
            if (!is_array($value) || $value === []) {
                return null;
            }
            return json_encode(array_values($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
