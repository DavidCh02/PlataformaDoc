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
}
