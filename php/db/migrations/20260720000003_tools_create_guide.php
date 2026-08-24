<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Panel-editable long-form content for a tool page.
 *
 * Until now every word on a tool page came from the frontend: the tool's name
 * and description from its `tds-tool-*` manifest, the guide from a TypeScript
 * module in `tds-tools-frontend/src/content/guides`. Correcting a sentence
 * therefore meant a package release and a site rebuild — the shape of "a
 * feature that can only be configured by editing a file on the host is, on this
 * host, a feature nobody has".
 *
 * The repo copies stay as the FALLBACK, the same contract the landingpage's
 * content blocks use: an empty or unreachable database renders the committed
 * text, so a tool page can never go blank.
 *
 * Class name prefixed with the module id and the version globally unique — the
 * in-process auto-migrator loads every module's migrations into ONE process and
 * ONE phinxlog. MySQL-8-safe (unsigned id, per the sibling migrations).
 *
 * The list-shaped fields are JSON-in-TEXT rather than child tables: they are
 * edited and read as a whole and never queried into, so a `tools_guide_step`
 * table would buy ordering semantics nobody needs.
 */
final class ToolsCreateGuide extends AbstractMigration
{
    public function change(): void
    {
        $this->table('tools_guide', ['signed' => false])
            ->addColumn('tool_id', 'string', ['limit' => 80])
            ->addColumn('lang', 'string', ['limit' => 5])
            // Display copy. NULL means "keep whatever the manifest says", which
            // is what lets an editor translate the guide without also having to
            // restate the tool's own name.
            ->addColumn('name', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('seo_title', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('seo_description', 'text', ['null' => true])
            // The guide itself; shapes mirror `ToolGuide` in the frontend.
            // intro / use_cases / related are string lists, steps and faq are
            // lists of objects.
            ->addColumn('intro', 'text', ['null' => true])
            ->addColumn('use_cases', 'text', ['null' => true])
            ->addColumn('steps', 'text', ['null' => true])
            ->addColumn('faq', 'text', ['null' => true])
            ->addColumn('related', 'text', ['null' => true])
            ->addColumn('privacy', 'text', ['null' => true])
            ->addColumn('machine_translated', 'boolean', ['default' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tool_id', 'lang'], ['unique' => true])
            ->create();
    }
}
