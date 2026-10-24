<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Premium as a monthly subscription (2026-10-24).
 *
 * - `tools_tier`: the stages an admin manages freely — name, monthly price,
 *   the advertising copy in both languages, and which tools a stage unlocks
 *   (`tool_ids`, a JSON list). A price change applies to new subscriptions;
 *   Stripe keeps a running subscription on the price it was started with.
 * - `tools_subscription`: one row per Stripe subscription, written only by
 *   the signed webhook. `status` mirrors Stripe's.
 * - `tools_config.blog_slug`: the Ratgeber article a tool links to (or shows,
 *   when the blog extension is composed).
 *
 * One-off purchases in `tools_entitlement` stay valid; the module simply
 * stops selling them. Unsigned ids (MySQL 8), NOT NULL primary keys; class and
 * version are unique across all extensions (one shared phinxlog).
 */
final class ToolsCreatePremiumTiers extends AbstractMigration
{
    public function change(): void
    {
        $this->table('tools_tier', ['signed' => false])
            ->addColumn('slug', 'string', ['limit' => 60])
            ->addColumn('name', 'string', ['limit' => 120])
            ->addColumn('name_en', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('tagline', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('tagline_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('features', 'text', ['null' => true])
            ->addColumn('features_en', 'text', ['null' => true])
            ->addColumn('price_cents', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('tool_ids', 'text', ['null' => true])
            ->addColumn('active', 'boolean', ['default' => true])
            ->addColumn('highlight', 'boolean', ['default' => false])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['slug'], ['unique' => true])
            ->create();

        $this->table('tools_subscription', ['signed' => false])
            ->addColumn('app_user_id', 'integer', ['signed' => false])
            ->addColumn('tier_id', 'integer', ['signed' => false])
            ->addColumn('stripe_subscription_id', 'string', ['limit' => 120])
            ->addColumn('stripe_customer_id', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 30, 'default' => 'incomplete'])
            ->addColumn('current_period_end', 'datetime', ['null' => true])
            ->addColumn('cancel_at_period_end', 'boolean', ['default' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['stripe_subscription_id'], ['unique' => true])
            ->addIndex(['app_user_id'])
            ->addIndex(['tier_id'])
            ->create();

        $this->table('tools_config')
            ->addColumn('blog_slug', 'string', ['limit' => 160, 'null' => true, 'after' => 'price_cents'])
            ->update();
    }
}
