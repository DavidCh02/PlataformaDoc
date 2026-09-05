<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminContentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleAndPermissionSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_all_users_can_view_content_from_every_user(): void
    {
        $doctor = $this->userWithRole('doctor');
        $other = $this->userWithRole('doctor');

        Folder::create(['name' => 'Carpeta del doctor', 'user_id' => $doctor->id]);
        Folder::create(['name' => 'Carpeta del otro', 'user_id' => $other->id]);

        $response = $this->actingAs($doctor)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Carpeta del doctor');
        $response->assertSee('Carpeta del otro');
    }

    public function test_listing_includes_the_creator_name(): void
    {
        $doctor = $this->userWithRole('doctor');
        $other = $this->userWithRole('doctor');

        Folder::create(['name' => 'Carpeta compartida', 'user_id' => $other->id]);
        $file = File::create([
            'name' => 'informe',
            'original_name' => 'informe.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => 'files/'.$other->id.'/informe.pdf',
            'file_size' => 100,
            'user_id' => $other->id,
        ]);

        $response = $this->actingAs($doctor)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee($other->name);
        $response->assertSee('Carpeta compartida');
        $response->assertSee($file->original_name);
    }

    public function test_admin_can_delete_folder_of_other_user(): void
    {
        $admin = $this->userWithRole('admin');
        $other = $this->userWithRole('doctor');

        $folder = Folder::create(['name' => 'Carpeta a borrar', 'user_id' => $other->id]);

        $response = $this->actingAs($admin)->delete(route('folders.destroy', $folder));

        $response->assertRedirect();
        $this->assertSoftDeleted('folders', ['id' => $folder->id]);
    }

    public function test_admin_can_delete_file_of_other_user(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $admin = $this->userWithRole('admin');
        $other = $this->userWithRole('doctor');

        $file = File::create([
            'name' => 'documento',
            'original_name' => 'documento.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => 'files/'.$other->id.'/documento.pdf',
            'file_size' => 100,
            'user_id' => $other->id,
        ]);

        $response = $this->actingAs($admin)->delete(route('files.destroy', $file));

        $response->assertRedirect();
        $this->assertSoftDeleted('files', ['id' => $file->id]);
    }

    public function test_doctor_with_delete_permission_can_delete_any_content(): void
    {
        $doctor = $this->userWithRole('doctor'); // el rol doctor incluye files.delete
        $other = $this->userWithRole('doctor');

        $folder = Folder::create(['name' => 'Carpeta ajena', 'user_id' => $other->id]);

        $response = $this->actingAs($doctor)->delete(route('folders.destroy', $folder));

        $response->assertRedirect();
        $this->assertSoftDeleted('folders', ['id' => $folder->id]);
    }

    public function test_users_without_delete_permission_cannot_delete(): void
    {
        $visitante = $this->userWithRole('visitante'); // sin files.delete
        $other = $this->userWithRole('doctor');

        $folder = Folder::create(['name' => 'Carpeta ajena', 'user_id' => $other->id]);

        $response = $this->actingAs($visitante)->delete(route('folders.destroy', $folder));

        $response->assertForbidden();
        $this->assertNotSoftDeleted('folders', ['id' => $folder->id]);
    }

    public function test_admin_can_delete_document_of_other_user(): void
    {
        $admin = $this->userWithRole('admin');
        $other = $this->userWithRole('doctor');

        $document = Document::create([
            'title' => 'Documento de otro usuario',
            'content' => '<p>Contenido</p>',
            'user_id' => $other->id,
        ]);

        $response = $this->actingAs($admin)->delete(route('documents.destroy', $document));

        $response->assertRedirect();
        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    public function test_all_users_page_is_accessible_by_admin(): void
    {
        $admin = $this->userWithRole('admin');

        $response = $this->actingAs($admin)->get(route('admin.users.index'));
        $response->assertOk();
    }

    public function test_all_users_page_is_forbidden_for_non_admin(): void
    {
        $doctor = $this->userWithRole('doctor');

        $response = $this->actingAs($doctor)->get(route('admin.users.index'));
        $response->assertForbidden();
    }
}