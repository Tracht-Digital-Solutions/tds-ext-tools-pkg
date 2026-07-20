<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tool catalog config schema. Class name prefixed with the module id (`Tools*`)
 * and migration version globally unique — the in-process auto-migrator includes
 * every module's migrations into ONE process + ONE phinxlog. MySQL-8-safe
 * (unsigned id). One row per tool id; `tool_id` is unique (the registry sync
 * upserts on it). Booleans stored as tinyint. No cross-domain FK — the tool list
 * is owned by the frontend packs, not another table.
 */
final class ToolsCreateConfig extends AbstractMigration
{
    public function change(): void
    {
        $this->table('tools_config', ['signed' => false])
            ->addColumn('tool_id', 'string', ['limit' => 80])
            ->addColumn('name', 'string', ['limit' => 200])
            ->addColumn('category', 'string', ['limit' => 40, 'default' => 'other'])
            ->addColumn('enabled', 'boolean', ['default' => true])
            ->addColumn('requires_login', 'boolean', ['default' => false])
            ->addColumn('is_premium', 'boolean', ['default' => false])
            ->addColumn('price_cents', 'integer', ['default' => 0])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tool_id'], ['unique' => true])
            ->create();
    }
}
