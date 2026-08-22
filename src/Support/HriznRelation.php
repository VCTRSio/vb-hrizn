<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Support;

use App\Support\EntityType;

/**
 * Plugin-local type & event vocabulary. The cross-plugin relation VERB comes from
 * core (App\Support\EntityRelation::COVERS), and the vehicle TARGET type is the
 * canonical core EntityType::INVENTORY_VEHICLE — aliased here so hrizn's vehicle
 * edges use the same stored value ('inventory.vehicle') that every sibling queries.
 * (Historic rows written before this alias carried the drifted 'inventory_vehicle'
 * value; per owner decision 2026-08-22 they are left as-is — no back-migration — so
 * this alias only makes NEW edges canonical.) The remaining source types, feed event
 * types, and article labels have no core registry, so they stay plugin-owned.
 */
final class HriznRelation
{
    public const PLUGIN_NAMESPACE = 'vb-hrizn';

    /** EntityReference source/target types. */
    public const CONTENT_SOURCE_TYPE = 'vb-hrizn.content';

    public const IDEACLOUD_SOURCE_TYPE = 'vb-hrizn.ideacloud';

    /** Canonical core vehicle type ('inventory.vehicle') — see class docblock. */
    public const VEHICLE_TARGET_TYPE = EntityType::INVENTORY_VEHICLE;

    /** Feed event types. */
    public const FEED_CONTENT_READY = 'hrizn.content.ready';

    public const FEED_CONTENT_FAILED = 'hrizn.content.failed';

    public const FEED_RESEARCH_READY = 'hrizn.ideacloud.ready';

    /** Human labels for the 7 article types (display only, not validation). */
    private const ARTICLE_LABELS = [
        'basic' => 'Article',
        'qa' => 'Q&A',
        'expert' => 'Expert Article',
        'modellanding' => 'Model Landing Page',
        'comparison' => 'Comparison',
        'salesevent' => 'Sales Event',
        'emailtemplate' => 'Email Template',
    ];

    public static function articleLabel(string $type): string
    {
        return self::ARTICLE_LABELS[$type] ?? ucfirst($type);
    }
}
