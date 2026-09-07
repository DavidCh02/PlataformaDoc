<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function ensurePermissions(array $names): void
    {
        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
    }

    private function actingAdmin(): User
    {
        $this->ensurePermissions(['users.manage']);
        $admin = User::factory()->create();
        $admin->givePermissionTo('users.manage');

        return $admin;
    }

    public function test_an_admin_can_list_users_with_roles(): void
    {
        $admin = $this->actingAdmin();
        User::factory()->count(3)->create();

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Index')
            ->has('users', 4));
    }

    public function test_users_without_manage_permission_are_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.audit-logs.index'))->assertForbidden();
    }

    public function test_an_admin_can_change_a_user_role_and_it_is_audited(): void
    {
        $admin = $this->actingAdmin();
        $target = User::factory()->create();
        Role::firstOrCreate(['name' => 'doctor']);
        Role::firstOrCreate(['name' => 'visitante']);
        $target->assignRole('doctor');

        $response = $this->actingAs($admin)->patch(route('admin.users.update-role', $target), [
            'role' => 'visitante',
        ]);

        $response->assertRedirect();
        $target->refresh();
        $this->assertTrue($target->hasRole('visitante'));
        $this->assertFalse($target->hasRole('doctor'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.user.role_update',
            'auditable_id' => $target->id,
        ]);
    }

    public function test_an_admin_cannot_change_their_own_role(): void
    {
        $admin = $this->actingAdmin();

        $this->actingAs($admin)
            ->patch(route('admin.users.update-role', $admin), ['role' => 'visitante'])
            ->assertForbidden();
    }

    public function test_an_admin_can_sync_direct_permissions_and_it_is_audited(): void
    {
        $admin = $this->actingAdmin();
        $target = User::factory()->create();
        Permission::firstOrCreate(['name' => 'files.upload']);
        Permission::firstOrCreate(['name' => 'files.view']);

        $response = $this->actingAs($admin)->patch(route('admin.users.sync-permissions', $target), [
            'permissions' => ['files.upload'],
        ]);

        $response->assertRedirect();
        $this->assertTrue($target->hasDirectPermission('files.upload'));
        $this->assertFalse($target->hasDirectPermission('files.view'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.user.permissions_sync',
            'auditable_id' => $target->id,
        ]);
    }

    public function test_invalid_role_is_rejected(): void
    {
        $admin = $this->actingAdmin();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->patch(route('admin.users.update-role', $target), ['role' => 'rol-inexistente'])
            ->assertSessionHasErrors('role');
    }

    public function test_an_admin_can_create_users_with_role_and_direct_permissions(): void
    {
        $admin = $this->actingAdmin();
        $this->ensurePermissions(['files.upload', 'files.view']);
        Role::firstOrCreate(['name' => 'practicante']);

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Nuevo Practicante',
            'email' => 'practicante@plataforma.test',
            'password' => 'password-seguro',
            'password_confirmation' => 'password-seguro',
            'role' => 'practicante',
            'permissions' => ['files.upload'],
        ]);

        $response->assertRedirect(route('admin.users.index'));

        $created = User::query()->where('email', 'practicante@plataforma.test')->firstOrFail();
        $this->assertTrue($created->hasRole('practicante'));
        $this->assertTrue($created->hasDirectPermission('files.upload'));
        $this->assertFalse($created->hasDirectPermission('files.view'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.user.created',
            'auditable_id' => $created->id,
        ]);
    }

    public function test_user_creation_requires_a_unique_email(): void
    {
        $admin = $this->actingAdmin();
        Role::firstOrCreate(['name' => 'doctor']);
        $existing = User::factory()->create(['email' => 'duplicado@plataforma.test']);

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Duplicado',
            'email' => $existing->email,
            'password' => 'password-seguro',
            'password_confirmation' => 'password-seguro',
            'role' => 'doctor',
            'permissions' => [],
        ])->assertSessionHasErrors('email');
    }

    public function test_creating_a_user_does_not_bake_role_defaults_as_direct_permissions(): void
    {
        $admin = $this->actingAdmin();
        $this->ensurePermissions(['files.view', 'files.upload']);
        $role = Role::firstOrCreate(['name' => 'practicante']);
        $role->givePermissionTo('files.view');

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Practicante Tres',
            'email' => 'practicante3@plataforma.test',
            'password' => 'password-seguro',
            'password_confirmation' => 'password-seguro',
            'role' => 'practicante',
            'permissions' => ['files.view', 'files.upload'],
        ]);

        $response->assertRedirect(route('admin.users.index'));

        $created = User::query()->where('email', 'practicante3@plataforma.test')->firstOrFail();
        $this->assertTrue($created->hasPermissionTo('files.view'));
        $this->assertTrue($created->hasDirectPermission('files.upload'));
        $this->assertFalse($created->hasDirectPermission('files.view'));
        $this->assertCount(1, $created->getDirectPermissions());
    }

    public function test_updating_a_user_does_not_bake_role_defaults_as_direct_permissions(): void
    {
        $admin = $this->actingAdmin();
        $this->ensurePermissions(['files.view', 'files.upload']);
        $role = Role::firstOrCreate(['name' => 'practicante']);
        $role->givePermissionTo('files.view');
        $target = User::factory()->create();
        $target->assignRole('practicante');

        $response = $this->actingAs($admin)->patch(route('admin.users.update', $target), [
            'role' => 'practicante',
            'permissions' => ['files.view', 'files.upload'],
        ]);

        $response->assertRedirect();
        $this->assertTrue($target->hasPermissionTo('files.view'));
        $this->assertTrue($target->hasDirectPermission('files.upload'));
        $this->assertFalse($target->hasDirectPermission('files.view'));
    }

    public function test_changing_a_user_role_drops_previous_role_permissions(): void
    {
        $admin = $this->actingAdmin();
        $this->ensurePermissions(['files.view', 'files.download']);
        $doctor = Role::firstOrCreate(['name' => 'doctor']);
        $doctor->givePermissionTo('files.download');
        $visitante = Role::firstOrCreate(['name' => 'visitante']);
        $visitante->givePermissionTo('files.view');
        $target = User::factory()->create();
        $target->assignRole('doctor');

        $response = $this->actingAs($admin)->patch(route('admin.users.update', $target), [
            'role' => 'visitante',
            'permissions' => ['files.view'],
        ]);

        $response->assertRedirect();
        $target->refresh();
        $this->assertTrue($target->hasRole('visitante'));
        $this->assertTrue($target->hasPermissionTo('files.view'));
        $this->assertFalse($target->hasPermissionTo('files.download'));
        $this->assertFalse($target->hasDirectPermission('files.download'));
    }

    public function test_removing_a_permission_from_a_role_deactivates_it_for_users(): void
    {
        $admin = $this->actingAdmin();
        $this->ensurePermissions(['files.view', 'files.upload']);
        $role = Role::firstOrCreate(['name' => 'doctor']);
        $role->givePermissionTo('files.view');
        $user = User::factory()->create();
        $user->assignRole('doctor');

        $this->assertTrue($user->hasPermissionTo('files.view'));

        $this->actingAs($admin)->patch(route('admin.permissions.update'), [
            'role' => 'doctor',
            'permissions' => ['files.upload'],
        ]);

        $user->refresh();
        $this->assertTrue($user->hasPermissionTo('files.upload'));
        $this->assertFalse($user->hasPermissionTo('files.view'));
    }

    public function test_removing_a_role_default_permission_from_a_single_user_is_enforced(): void
    {
        $admin = $this->actingAdmin();
        $this->ensurePermissions(['docs.create', 'files.view', 'files.upload']);
        $role = Role::firstOrCreate(['name' => 'doctor']);
        $role->givePermissionTo(['docs.create', 'files.view', 'files.upload']);
        $target = User::factory()->create();
        $target->assignRole('doctor');
        $other = User::factory()->create();
        $other->assignRole('doctor');

        $this->assertTrue($target->hasPermissionTo('docs.create'));

        $response = $this->actingAs($admin)->patch(route('admin.users.update', $target), [
            'role' => 'doctor',
            'permissions' => ['files.view', 'files.upload'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $target->refresh();

        $this->assertFalse($target->hasPermissionTo('docs.create'));
        $this->assertTrue($target->hasPermissionTo('files.view'));
        $this->assertFalse($target->can('docs.create'));
        $this->assertFalse($target->getAllPermissions()->contains(fn ($permission) => $permission->name === 'docs.create'));

        // El resto de usuarios con el mismo rol conservan el permiso.
        $this->assertTrue($other->hasPermissionTo('docs.create'));

        $this->assertDatabaseHas('user_permission_exemptions', [
            'user_id' => $target->id,
            'permission_id' => Permission::query()->where('name', 'docs.create')->value('id'),
        ]);
    }

    public function test_reenabling_an_exempted_permission_clears_the_exemption(): void
    {
        $admin = $this->actingAdmin();
        $this->ensurePermissions(['docs.create', 'files.view']);
        $role = Role::firstOrCreate(['name' => 'doctor']);
        $role->givePermissionTo(['docs.create', 'files.view']);
        $target = User::factory()->create();
        $target->assignRole('doctor');

        $this->actingAs($admin)->patch(route('admin.users.update', $target), [
            'role' => 'doctor',
            'permissions' => ['files.view'],
        ]);

        $target->refresh();
        $this->assertFalse($target->hasPermissionTo('docs.create'));

        $this->actingAs($admin)->patch(route('admin.users.update', $target), [
            'role' => 'doctor',
            'permissions' => ['docs.create', 'files.view'],
        ]);

        $target->refresh();
        $this->assertTrue($target->hasPermissionTo('docs.create'));
        $this->assertDatabaseMissing('user_permission_exemptions', ['user_id' => $target->id]);
    }

    public function test_creating_a_user_can_exempt_a_role_default_permission(): void
    {
        $admin = $this->actingAdmin();
        $this->ensurePermissions(['docs.create', 'files.view']);
        $role = Role::firstOrCreate(['name' => 'practicante']);
        $role->givePermissionTo(['docs.create', 'files.view']);

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'User Exento',
            'email' => 'exento@plataforma.test',
            'password' => 'password-seguro',
            'password_confirmation' => 'password-seguro',
            'role' => 'practicante',
            'permissions' => ['files.view'],
        ]);

        $created = User::query()->where('email', 'exento@plataforma.test')->firstOrFail();
        $this->assertTrue($created->hasPermissionTo('files.view'));
        $this->assertFalse($created->hasPermissionTo('docs.create'));
        $this->assertDatabaseHas('user_permission_exemptions', ['user_id' => $created->id]);
    }

    public function test_users_without_manage_permission_cannot_create_users(): void
    {
        $user = User::factory()->create();
        $this->ensurePermissions(['files.view']);
        $user->givePermissionTo('files.view');

        $this->actingAs($user)->post(route('admin.users.store'), [
            'name' => 'No autorizado',
            'email' => 'no-autorizado@plataforma.test',
            'password' => 'password-seguro',
            'password_confirmation' => 'password-seguro',
            'role' => 'doctor',
            'permissions' => [],
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'no-autorizado@plataforma.test']);
    }

    public function test_an_admin_can_view_the_role_permissions_matrix(): void
    {
        $admin = $this->actingAdmin();
        $this->ensurePermissions(['files.view']);
        $role = Role::firstOrCreate(['name' => 'doctor']);
        $role->givePermissionTo('files.view');

        $response = $this->actingAs($admin)->get(route('admin.permissions.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Permissions/Index')
            ->has('roles', 1)
            ->where('roles.0.name', 'doctor'));
    }

    public function test_role_permissions_can_be_synced_and_are_audited(): void
    {
        $admin = $this->actingAdmin();
        $this->ensurePermissions(['files.view', 'files.upload', 'files.download']);
        $role = Role::firstOrCreate(['name' => 'doctor']);
        $role->givePermissionTo('files.view');

        $response = $this->actingAs($admin)->patch(route('admin.permissions.update'), [
            'role' => 'doctor',
            'permissions' => ['files.upload', 'files.download'],
        ]);

        $response->assertRedirect();
        $role->refresh();
        $this->assertCount(2, $role->permissions);
        $this->assertTrue($role->hasPermissionTo('files.upload'));
        $this->assertFalse($role->hasPermissionTo('files.view'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.role_permissions_updated',
            'auditable_id' => $role->id,
        ]);
    }

    public function test_users_manage_cannot_be_removed_from_the_admin_role(): void
    {
        $admin = $this->actingAdmin();
        $this->ensurePermissions(['users.manage', 'files.delete']);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->givePermissionTo(['users.manage', 'files.delete']);

        $this->actingAs($admin)->patch(route('admin.permissions.update'), [
            'role' => 'admin',
            'permissions' => ['files.delete'],
        ])->assertSessionHasErrors('permissions');

        $this->assertTrue($adminRole->fresh()->hasPermissionTo('users.manage'));
    }

    public function test_role_permissions_update_requires_manage_permission(): void
    {
        $user = User::factory()->create();
        $this->ensurePermissions(['files.view']);
        $user->givePermissionTo('files.view');
        Role::firstOrCreate(['name' => 'doctor']);

        $this->actingAs($user)
            ->patch(route('admin.permissions.update'), [
                'role' => 'doctor',
                'permissions' => ['files.view'],
            ])
            ->assertForbidden();
    }
}
