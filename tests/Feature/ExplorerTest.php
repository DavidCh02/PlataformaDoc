<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Event;
use App\Events\DocumentUpdated;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ExplorerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_create_a_folder_with_the_direct_permission(): void
    {
        $user = $this->userWithPermissions(['folders.create', 'files.view']);

        $response = $this->actingAs($user)->post(route('folders.store'), [
            'name' => 'Historias clínicas',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('folders', [
            'name' => 'Historias clínicas',
            'user_id' => $user->id,
        ]);
    }

    public function test_documents_inside_a_folder_are_not_shown_in_the_root(): void
    {
        $user = $this->userWithPermissions(['files.view']);
        $folder = Folder::create(['name' => 'Caso 1', 'user_id' => $user->id]);
        $document = Document::create([
            'title' => 'Oficio dentro de Caso 1',
            'content' => '<p>Contenido</p>',
            'folder_id' => $folder->id,
            'user_id' => $user->id,
        ]);

        $rootResponse = $this->actingAs($user)->get(route('dashboard'));
        $rootResponse->assertOk();
        $rootResponse->assertDontSee($document->title);

        $folderResponse = $this->actingAs($user)->get(route('dashboard', ['folder_id' => $folder->id]));
        $folderResponse->assertOk();
        $folderResponse->assertSee($document->title);
    }

    public function test_a_user_can_upload_and_download_a_supported_file(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $user = $this->userWithPermissions(['files.upload', 'files.download']);

        $uploadResponse = $this->actingAs($user)->post(route('files.store'), [
            'file' => UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'),
        ]);

        $uploadResponse->assertRedirect();
        $file = File::query()->firstOrFail();
        Storage::disk('local')->assertExists($file->storage_path);

        $downloadResponse = $this->actingAs($user)->get(route('files.download', $file));

        $downloadResponse->assertDownload('report.pdf');
    }

    public function test_a_file_can_be_restored_from_the_trash(): void
    {
        $user = $this->userWithPermissions(['files.delete']);
        $file = File::create($this->fileAttributes($user));
        $file->delete();

        $response = $this->actingAs($user)->post(route('files.restore', $file->id));

        $response->assertRedirect();
        $this->assertDatabaseHas('files', [
            'id' => $file->id,
            'deleted_at' => null,
        ]);
    }

    public function test_a_direct_permission_allows_a_practicante_to_delete_a_file(): void
    {
        $user = User::factory()->create();
        $permission = Permission::firstOrCreate(['name' => 'files.delete', 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
        $file = File::create($this->fileAttributes($user));

        $response = $this->actingAs($user)->delete(route('files.destroy', $file));

        $response->assertRedirect();
        $this->assertSoftDeleted('files', ['id' => $file->id]);
    }

    public function test_a_docx_can_be_imported_as_an_editable_document(): void
    {
        $user = $this->userWithPermissions(['docs.create']);
        $phpWord = new PhpWord();
        $phpWord->addSection()->addText('Contenido importado');
        $temporaryPath = tempnam(sys_get_temp_dir(), 'docx_').'.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($temporaryPath);

        $response = $this->actingAs($user)->post(route('documents.import-word'), [
            'file' => UploadedFile::fake()->createWithContent(
                'informe.docx',
                file_get_contents($temporaryPath),
            ),
        ]);

        @unlink($temporaryPath);
        $response->assertRedirect();
        $this->assertDatabaseHas('documents', [
            'title' => 'informe',
            'user_id' => $user->id,
        ]);
        $this->assertStringNotContainsString('<body>', Document::query()->latest('id')->value('content'));
    }

    public function test_a_yjs_delta_is_broadcast_for_an_editable_document(): void
    {
        Event::fake();
        $user = $this->userWithPermissions(['docs.edit_realtime']);
        $document = Document::create([
            'title' => 'Documento colaborativo',
            'content' => '<p>Inicial</p>',
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->postJson(route('documents.sync', $document), [
            'delta' => base64_encode('yjs-update'),
        ]);

        $response->assertOk()->assertJson(['broadcast' => true]);
        Event::assertDispatched(DocumentUpdated::class, function (DocumentUpdated $event) use ($document, $user): bool {
            return $event->documentId === $document->id
                && $event->delta === base64_encode('yjs-update')
                && $event->userId === $user->id;
        });
    }

    public function test_an_existing_docx_file_can_be_opened_in_the_editor(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $user = $this->userWithPermissions(['files.view', 'docs.edit_realtime']);
        $phpWord = new PhpWord();
        $phpWord->addSection()->addText('Documento ya cargado');
        $temporaryPath = tempnam(sys_get_temp_dir(), 'docx_').'.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($temporaryPath);
        $storagePath = 'files/'.$user->id.'/cargado.docx';
        Storage::disk('local')->put($storagePath, file_get_contents($temporaryPath));
        $file = File::create([
            'name' => 'cargado',
            'original_name' => 'cargado.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'storage_path' => $storagePath,
            'file_size' => Storage::disk('local')->size($storagePath),
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('files.edit', $file));

        @unlink($temporaryPath);
        $response->assertRedirect();
        $this->assertDatabaseHas('documents', [
            'title' => 'cargado',
            'user_id' => $user->id,
        ]);
        $this->assertNotNull($file->fresh()->document_id);
    }

    public function test_a_docx_blob_is_returned_as_an_inline_binary_response(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $user = $this->userWithPermissions(['files.view']);
        $storagePath = 'files/'.$user->id.'/original.docx';
        Storage::disk('local')->put($storagePath, 'docx-binary');
        $file = File::create([
            'name' => 'original',
            'original_name' => 'original.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'storage_path' => $storagePath,
            'file_size' => 11,
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('files.blob', $file));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permissionName) {
            $permission = Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function fileAttributes(User $user): array
    {
        return [
            'name' => 'report',
            'original_name' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => 'files/'.$user->id.'/report.pdf',
            'file_size' => 100,
            'user_id' => $user->id,
        ];
    }
}
