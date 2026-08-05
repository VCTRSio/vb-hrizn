<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Support;

/**
 * Plugin-local relation & event vocabulary. Core does not validate the relation
 * string on EntityReferenceService::link(), so the plugin owns these consts —
 * keeping cross-plugin linking and feed/task emission zero-core.
 */
final class HriznRelation
{
    public const PLUGIN_NAMESPACE = 'vb-hrizn';

    /** EntityReference source/target types. */
    public const CONTENT_SOURCE_TYPE = 'vb-hrizn.content';
    public const IDEACLOUD_SOURCE_TYPE = 'vb-hrizn.ideacloud';
    public const VEHICLE_TARGET_TYPE = 'inventory_vehicle';

    /** Relation verb: a content piece covers a specific vehicle. */
    public const COVERS = 'covers';

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
