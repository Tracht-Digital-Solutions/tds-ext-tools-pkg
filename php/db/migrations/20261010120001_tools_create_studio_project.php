<?php
declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Saved Designsystem-Studio projects (Premium).
 *
 * Class name prefixed with the module id and the version globally unique — all
 * extensions share one phinxlog. MySQL-8-safe: unsigned id, NOT NULL key.
 * `app_user_id` references tds-auth-api's app_user without a cross-domain FK,
 * like `tools_entitlement`. `data` is the studio's versioned project JSON,
 * stored whole (MEDIUMTEXT; the API caps one project at 512 KB).
 */
final class ToolsCreateStudioProject extends AbstractMigration
{
    public function change(): void
    {
        $this->table('tools_studio_project', ['signed' => false])
            ->addColumn('app_user_id', 'integer', ['signed' => false])
            ->addColumn('name', 'string', ['limit' => 120])
            ->addColumn('schema_version', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('data', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['app_user_id', 'updated_at'])
            ->create();
    }
}
