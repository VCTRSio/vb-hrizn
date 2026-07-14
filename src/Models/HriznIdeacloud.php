<?php

namespace Vctrs\Plugins\VbHrizn\Models;

use App\Plugins\Concerns\AdminManageable;
use App\Plugins\Contracts\AdminManageableModel;
use App\Plugins\PluginModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_type
 * @property string $tenant_id
 * @property string $keyword
 * @property string $status
 * @property string $hrizn_id
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
class HriznIdeacloud extends PluginModel implements AdminManageableModel
{
    use AdminManageable;

    protected $table = 'hrizn_ideaclouds';

    /** @return HasMany<HriznContent, $this> */
    public function content(): HasMany
    {
        return $this->hasMany(HriznContent::class, 'ideacloud_id');
    }
}
