<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateRolePermissionsRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionController extends Controller
{
    public function index(): Response
    {
        $this->authorize('manage', User::class);

        $roles = Role::query()
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->sort()->values(),
            ]);

        $permissions = Permission::query()
            ->with('roles:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Permission $permission) => [
                'id' => $permission->id,
                'name' => $permission->name,
                'roles' => $permission->roles->pluck('name')->sort()->values(),
            ]);

        return Inertia::render('Admin/Permissions/Index', [
            'roles' => $roles,
            'permissions' => $permissions,
        ]);
    }

    public function update(UpdateRolePermissionsRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('manage', User::class);

        $role = Role::query()->where('name', $request->validated('role'))->firstOrFail();
        $permissions = collect($request->validated('permissions'))->sort()->values();
        $role->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $auditLogger->log('admin.role_permissions_updated', $role, [
            'role' => $role->name,
            'permissions' => $permissions->all(),
        ]);

        return back()->with('success', "Permisos del rol «{$role->name}» actualizados.");
    }
}