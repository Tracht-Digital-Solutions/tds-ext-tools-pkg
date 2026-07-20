<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Premium entitlement schema. Class name prefixed with the module id (`Tools*`)
 * and version globally unique (shared phinxlog). MySQL-8-safe (unsigned ids).
 * One active entitlement per (user, tool) — unique on (app_user_id, tool_id).
 * `app_user_id` references tds-auth-api's app_user but carries NO cross-domain FK
 * (identity lives in another service — same rule as billing/ticket customer_id).
 */
final class ToolsCreateEntitlement extends AbstractMigration
{
    public function change(): void
    {
        $this->table('tools_entitlement', ['signed' => false])
            ->addColumn('app_user_id', 'integer', ['signed' => false])
            ->addColumn('tool_id', 'string', ['limit' => 80])
            ->addColumn('status', 'enum', ['values' => ['active', 'revoked'], 'default' => 'active'])
            ->addColumn('source_stripe_id', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('expires_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['app_user_id', 'tool_id'], ['unique' => true])
            ->create();
    }
}
