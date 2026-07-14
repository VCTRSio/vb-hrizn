<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Http\Controllers;

use Vctrs\Plugins\VbHrizn\Http\Controllers\Admin\AdminController;
use Vctrs\Plugins\VbHrizn\Models\HriznContent;

class ContentAdminController extends AdminController
{
    protected function model(): string
    {
        return HriznContent::class;
    }

    protected function rules(): array
    {
        // Core admin-router.ts contentPatchSchema (24-27): articleType ∈ {basic, qa};
        // contentIntent ∈ {fixed_ops, variable} (nullable). Externally-attested
        // fields (status/progress/compliance) are NOT admin-editable.
        return [
            'article_type' => ['sometimes', 'in:basic,qa'],
            'content_intent' => ['sometimes', 'nullable', 'in:fixed_ops,variable'],
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
