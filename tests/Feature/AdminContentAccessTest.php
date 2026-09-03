<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminContentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleAndPermissionSeeder::class);
    }

    private function createUserWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        return $user;
    }

    public function test_admin_can_view_all_users_folders(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $otherUser = User::factory()->create();
        $otherUser->assignRole('doctor');

        $folder1 = Folder::create(['name' => 'Carpeta Doctor', 'user_id' => $otherUser->id]);
        $folder2 = Folder::create(['name' => 'Carpeta Admin', 'user_id' => $admin->id]);

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Carpeta Doctor');
        $response->assertSee('Carpeta Admin');
    }

    public function test_admin_can_filter_by_user_id(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $otherUser = User::factory()->create();
        $otherUser->assignRole('doctor');

        $folder1 = Folder::create(['name' => 'Doctor Folder', 'user_id' => $otherUser->id]);
        $folder2 = Folder::create(['name' => 'Admin Folder', 'user_id' => $admin->id]);

        $response = $this->actingAs($admin)->get(route('dashboard', ['user_id' => $otherUser->id]));

        $response->assertOk();
        $response->assertSee('Doctor Folder');
        $response->assertDontSee('Admin Folder');
    }

    public function test_admin_can_delete_folder_of_other_user(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $otherUser = User::factory()->create();
        $otherUser->assignRole('doctor');

        $folder = Folder::create(['name' => 'Carpeta a borrar', 'user_id' => $otherUser->id]);

        $response = $this->actingAs($admin)->delete(route('folders.destroy', $folder));

        $response->assertRedirect();
        $this->assertSoftDeleted('folders', ['id' => $folder->id]);
    }

        public function test_admin_can_delete_file_of_other_user(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $otherUser = User::factory()->create();
        $otherUser->assignRole('doctor');

        $file = File::create([
            'name' => 'documento',
            'original_name' => 'documento.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => 'files/'.$otherUser->id.'/documento.pdf',
            'file_size' => 100,
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($admin)->delete(route('files.destroy', $file));

        $response->assertRedirect();
        $this->assertSoftDeleted('files', ['id' => $file->id]);
    }

    public function test_admin_can_force_delete_file_of_other_user(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $otherUser = User::factory()->create();
        $otherUser->assignRole('doctor');

        $file = File::create([
            'name' => 'documento',
            'original_name' => 'documento.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => 'files/'.$otherUser->id.'/documento.pdf',
            'file_size' => 100,
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($admin)->delete(route('files.force-destroy', $file->id));

        $response->assertRedirect();
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }

    public function test_admin_can_delete_document_of_other_user(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $otherUser = User::factory()->create();
        $otherUser->assignRole('doctor');

        $document = Document::create([
            'title' => 'Documento de otro usuario',
            'content' => '<p>Contenido</p>',
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($admin)->delete(route('documents.destroy', $document));

        $response->assertRedirect();
        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    public function test_admin_can_force_delete_document_of_other_user(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $otherUser = User::factory()->create();
        $otherUser->assignRole('doctor');

        $document = Document::create([
            'title' => 'Documento de otro usuario',
            'content' => '<p>Contenido</p>',
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($admin)->delete(route('documents.force-destroy', $document->id));

        $response->assertRedirect();
        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
    }

    public function test_non_admin_cannot_delete_content_of_other_user(): void
    {
        $doctor = User::factory()->create();
        $doctor->assignRole('doctor');

        $otherUser = User::factory()->create();
        $otherUser->assignRole('doctor');

        $folder = Folder::create(['name' => 'Carpeta ajena', 'user_id' => $otherUser->id]);

        $response = $this->actingAs($doctor)->delete(route('folders.destroy', $folder));
        $response->assertForbidden();
    }

    public function test_non_admin_can_only_see_own_content(): void
    {
        $doctor = User::factory()->create();
        $doctor->assignRole('doctor');

        $otherUser = User::factory()->create();
        $otherUser->assignRole('doctor');

        Folder::create(['name' => 'Doctor Folder', 'user_id' => $doctor->id]);
        Folder::create(['name' => 'Other Doctor Folder', 'user_id' => $otherUser->id]);

        $response = $this->actingAs($doctor)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Doctor Folder');
        $response->assertDontSee('Other Doctor Folder');
    }

    public function test_all_users_page_is_accessible_by_admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('admin.users.index'));
        $response->assertOk();
    }

    public function test_all_users_page_is_forbidden_for_non_admin(): void
    {
        $doctor = User::factory()->create();
        $doctor->assignRole('doctor');

        $response = $this->actingAs($doctor)->get(route('admin.users.index'));
        $response->assertForbidden();
    }
}