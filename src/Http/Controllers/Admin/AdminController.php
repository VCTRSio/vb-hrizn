<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbHrizn\Http\Controllers\Admin;

use App\Audit\AuditContext;
use App\Http\Controllers\Controller;
use App\Plugins\Contracts\AdminManageableModel;
use App\Rbac\PermissionResolver;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON admin base for the extracted plugin. Faithful copy of core
 * App\Plugins\Admin\PluginAdminController's authorise→find→validate→audit→trait flow,
 * but returns ApiResponse::success(...) instead of back() (the ESM UI calls these via the
 * axios kit; there is no server-side back() redirect target). Reuses the CORE AdminManageable trait
 * methods on the models. See extraction playbook Gotcha #17.
 */
abstract class AdminController extends Controller
{
    /** @return class-string<Model&AdminManageableModel> */
    abstract protected function model(): string;

    /** @return array<string, mixed> */
    abstract protected function rules(): array;

    abstract protected function permission(): string;

    abstract protected function procedurePrefix(): string;

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorise();
        $model = $this->findManaged($id);
        $validated = $request->validate($this->rules());
        AuditContext::tag("{$this->procedurePrefix()}.admin.update");
        $model->applyAdminEdit($validated, $this->actorId());

        return ApiResponse::success(['id' => $model->getKey()]);
    }

    public function softDelete(Request $request, string $id): JsonResponse
    {
        $this->authorise();
        $model = $this->findManaged($id);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        AuditContext::tag("{$this->procedurePrefix()}.admin.softDelete");
        $model->softDeleteWithReason($validated['reason'] ?? null, $this->actorId());

        return ApiResponse::success(['id' => $model->getKey()]);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $this->authorise();
        $model = $this->findManaged($id, withTrashed: true);
        AuditContext::tag("{$this->procedurePrefix()}.admin.restore");
        $model->restoreSoftDeleted();

        return ApiResponse::success(['id' => $model->getKey()]);
    }

    /** @return Model&AdminManageableModel */
    private function findManaged(string $id, bool $withTrashed = false): Model
    {
        $query = $this->model()::query();
        if (! $withTrashed) {
            $query->whereNull('deleted_at');
        }

        /** @var Model&AdminManageableModel */
        return $query->findOrFail($id);
    }

    private function authorise(): void
    {
        $perms = app(TenantContext::class)->permissions();
        abort_unless(app(PermissionResolver::class)->grants($perms, $this->permission()), 403);
    }

    private function actorId(): string
    {
        return app(TenantContext::class)->userId();
    }
}
