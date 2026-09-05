<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\SyncUserPermissionsRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(): Response
    {
        $this->authorize('manage', User::class);

        $users = User::query()
            ->with('roles:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name'),
                'permissions_count' => $user->getAllPermissions()->count(),
            ]);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'roles' => Role::query()->orderBy('name')->pluck('name'),
            'permissions' => Permission::query()->orderBy('name')->pluck('name'),
            'rolePermissions' => $this->rolePermissionsMap(),
        ]);
    }

    public function store(StoreUserRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('manage', User::class);

        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
        ]);

        // El rol se asigna como etiqueta y base; los permisos marcados se guardan
        // como permisos efectivos (se pueden ajustar después).
        $user->assignRole($request->validated('role'));
        $user->syncPermissions($request->validated('permissions'));

        $auditLogger->log('admin.user.created', $user, [
            'email' => $user->email,
            'role' => $request->validated('role'),
            'permissions' => $request->validated('permissions'),
        ]);

        return redirect()
            ->route('admin.users.index')
            ->with('success', "Usuario {$user->name} creado correctamente.");
    }

    public function edit(User $user): Response
    {
        $this->authorize('update', $user);

        $allPermissions = Permission::query()->orderBy('name')->pluck('name');
        $effectivePermissions = $user->getAllPermissions()->pluck('name');

        return Inertia::render('Admin/Users/Edit', [
            'managedUser' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->roles->first()?->name,
            ],
            'roles' => Role::query()->orderBy('name')->pluck('name'),
            'rolePermissions' => $this->rolePermissionsMap(),
            'permissions' => $allPermissions->map(fn (string $name) => [
                'name' => $name,
                'active' => $effectivePermissions->contains($name),
            ])->values(),
        ]);
    }

    /**
     * Guarda rol + permisos de una sola vez (flujo consolidado).
     */
    public function update(UpdateUserRequest $request, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('update', $user);

        $previousRole = $user->roles->first()?->name;
        $user->syncRoles([$request->validated('role')]);
        $user->syncPermissions($request->validated('permissions'));

        $auditLogger->log('admin.user.updated', $user, [
            'previous_role' => $previousRole,
            'new_role' => $request->validated('role'),
            'permissions' => $request->validated('permissions'),
        ]);

        return back()->with('success', 'Rol y permisos actualizados correctamente.');
    }

    public function updateRole(UpdateUserRoleRequest $request, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('update', $user);

        $previousRole = $user->roles->first()?->name;
        $user->syncRoles([$request->validated('role')]);

        $auditLogger->log('admin.user.role_update', $user, [
            'previous_role' => $previousRole,
            'new_role' => $request->validated('role'),
        ]);

        return back()->with('success', 'Rol actualizado correctamente.');
    }

    public function syncPermissions(SyncUserPermissionsRequest $request, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('update', $user);

        $directPermissions = collect($request->validated('permissions'));
        $user->syncPermissions($directPermissions);

        $auditLogger->log('admin.user.permissions_sync', $user, [
            'direct_permissions' => $directPermissions->values()->all(),
        ]);

        return back()->with('success', 'Permisos individuales actualizados.');
    }

    /**
     * Mapa de permisos por rol, usado por el frontend para precargar los
     * permisos por defecto al seleccionar un rol.
     */
    private function rolePermissionsMap(): array
    {
        return Role::query()
            ->with('permissions:id,name')
            ->get()
            ->mapWithKeys(fn (Role $role) => [
                $role->name => $role->permissions->pluck('name')->sort()->values()->all(),
            ])
            ->all();
    }
}
