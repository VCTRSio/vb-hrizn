<?php

namespace Vctrs\Plugins\VbHrizn\Models;

use App\Plugins\Concerns\AdminManageable;
use App\Plugins\Contracts\AdminManageableModel;
use App\Plugins\PluginModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_type
 * @property string $tenant_id
 * @property string $ideacloud_id
 * @property string|null $hrizn_content_id
 * @property string $article_type
 * @property string|null $content_intent
 * @property bool $auto_compliance
 * @property bool $auto_content_tools
 * @property string $status
 * @property int $progress_percent
 * @property string|null $progress_stage
 * @property string|null $compliance_status
 * @property int|null $compliance_score
 * @property string|null $error_message
 * @property string $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property string|null $deleted_by_id
 * @property string|null $delete_reason
 * @property Carbon|null $edited_at
 * @property string|null $edited_by_id
 * @property int $edit_count
 */
class HriznContent extends PluginModel implements AdminManageableModel
{
    use AdminManageable;

    protected $table = 'hrizn_content';

    protected $casts = [
        'auto_compliance' => 'boolean',
        'auto_content_tools' => 'boolean',
        'progress_percent' => 'integer',
        'compliance_score' => 'integer',
    ];

    /** @return BelongsTo<HriznIdeacloud, $this> */
    public function ideacloud(): BelongsTo
    {
        return $this->belongsTo(HriznIdeacloud::class, 'ideacloud_id');
    }
}
