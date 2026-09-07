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
use Spatie\Permission\PermissionRegistrar;

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

        $roleName = $request->validated('role');
        $pickedPermissions = collect($request->validated('permissions'));
        $roleDefaults = collect($this->rolePermissionsMap()[$roleName] ?? []);

        // Los permisos marcados que no pertenecen al rol se guardan como directos;
        // los permisos del rol que quedaron desmarcados se guardan como exenciones
        // para ESTE usuario (aunque el rol los conceda).
        $directPermissions = $pickedPermissions->diff($roleDefaults)->values();
        $exemptions = $roleDefaults->diff($pickedPermissions)->values();

        $user->assignRole($roleName);
        $user->syncPermissions($directPermissions);
        $user->syncExemptedPermissions($exemptions->all());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $auditLogger->log('admin.user.created', $user, [
            'email' => $user->email,
            'role' => $roleName,
            'direct_permissions' => $directPermissions->all(),
            'exemptions' => $exemptions->all(),
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
        $newRole = $request->validated('role');
        $pickedPermissions = collect($request->validated('permissions'));
        $roleDefaults = collect($this->rolePermissionsMap()[$newRole] ?? []);

        // Se guardan como directos solo los permisos que no son por defecto del rol.
        // Al cambiar de rol no se arrastran permisos antiguos; y los permisos del rol
        // desmarcados se guardan como exenciones para este usuario.
        $directPermissions = $pickedPermissions->diff($roleDefaults)->values();
        $exemptions = $roleDefaults->diff($pickedPermissions)->values();

        $user->syncRoles([$newRole]);
        $user->syncPermissions($directPermissions);
        $user->syncExemptedPermissions($exemptions->all());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $auditLogger->log('admin.user.updated', $user, [
            'previous_role' => $previousRole,
            'new_role' => $newRole,
            'direct_permissions' => $directPermissions->all(),
            'exemptions' => $exemptions->all(),
        ]);

        return back()->with('success', 'Rol y permisos actualizados correctamente.');
    }

    public function updateRole(UpdateUserRoleRequest $request, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('update', $user);

        $previousRole = $user->roles->first()?->name;
        $user->syncRoles([$request->validated('role')]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

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
        app(PermissionRegistrar::class)->forgetCachedPermissions();

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
