<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Http\Controllers;

use Vctrs\Plugins\VbHrizn\Http\Controllers\Admin\AdminController;
use Vctrs\Plugins\VbHrizn\Models\HriznIdeacloud;

class IdeacloudAdminController extends AdminController
{
    protected function model(): string
    {
        return HriznIdeacloud::class;
    }

    protected function rules(): array
    {
        // Core admin-router.ts ideacloudPatchSchema (20-22): keyword only —
        // status is externally driven by the Hrizn API and not admin-editable.
        return [
            'keyword' => ['sometimes', 'string', 'min:1', 'max:255'],
        ];
    }

    protected function permission(): string
    {
        return 'hrizn.admin.manage.rooftop';
    }

    protected function procedurePrefix(): string
    {
        return 'hrizn';
    }
}
