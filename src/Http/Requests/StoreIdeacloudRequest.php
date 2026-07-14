<?php

namespace Vctrs\Plugins\VbHrizn\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreIdeacloudRequest extends FormRequest
{
    public function rules(): array
    {
        // Core: z.string().min(3).max(500) (router.ts ideaclouds.create, line 476).
        return [
            'keyword' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
