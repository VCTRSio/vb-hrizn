<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Support;

/**
 * Plugin-local type & event vocabulary. The cross-plugin relation VERB now comes
 * from core (App\Support\EntityRelation::COVERS); what remains here is the
 * EntityReference source/target TYPE strings, the feed event types, and the article
 * labels — none of which have a core registry. Core does not validate the relation
 * string on EntityReferenceService::link(), so these stay plugin-owned.
 */
final class HriznRelation
{
    public const PLUGIN_NAMESPACE = 'vb-hrizn';

    /** EntityReference source/target types. */
    public const CONTENT_SOURCE_TYPE = 'vb-hrizn.content';

    public const IDEACLOUD_SOURCE_TYPE = 'vb-hrizn.ideacloud';

    public const VEHICLE_TARGET_TYPE = 'inventory_vehicle';

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
