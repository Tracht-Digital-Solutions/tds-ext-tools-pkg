<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Remove runtime GitHub configuration; repository releases remain CI-owned. */
final class ToolsRemoveGithubRuntime extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('app_setting')) {
            $this->execute(
                "DELETE FROM app_setting WHERE namespace = 'tools' "
                . "AND skey IN ('rebuild_repo', 'rebuild_workflow', 'rebuild_token')"
            );
        }
    }

    public function down(): void
    {
        // Deleted secrets cannot and must not be reconstructed on rollback.
    }
}
