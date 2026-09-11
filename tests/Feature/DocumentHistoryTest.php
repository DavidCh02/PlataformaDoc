<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DocumentHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['filesystems.default' => 'local']);
    }

    public function test_the_history_page_lists_versions_in_the_timeline(): void
    {
        $user = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($user, 'Informe', 'v1');

        DocumentVersion::create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'file_path' => 'files/'.$user->id.'/informe-v2.docx',
            'file_size' => 20,
            'version_number' => 'v2',
            'change_summary' => 'Corregí errores de redacción.',
        ]);

        $response = $this->actingAs($user)->get(route('documents.history', $document));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('DocumentHistory')
            ->where('document.title', 'Informe')
            ->where('current_version', 'v1')
            ->has('versions', 2)
            ->where('versions.0.version_number', 'v2')
            ->where('versions.1.version_number', 'v1')
            ->where('versions.1.user.name', $user->name));
    }

    public function test_a_user_can_comment_on_a_version(): void
    {
        $user = $this->userWithPermissions(['files.view']);
        $commenter = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($user, 'Acta', 'v1');

        $response = $this->actingAs($commenter)->post(
            route('document-versions.annotations', $document->currentVersion),
            ['comment' => 'Falta el sello en la página 2.'],
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('document_annotations', [
            'document_version_id' => $document->current_version_id,
            'user_id' => $commenter->id,
            'comment' => 'Falta el sello en la página 2.',
        ]);
    }

    public function test_an_empty_comment_is_rejected(): void
    {
        $user = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($user, 'Acta', 'v1');

        $this->actingAs($user)->post(
            route('document-versions.annotations', $document->currentVersion),
            ['comment' => '   '],
        )->assertStatus(422);
    }

    public function test_a_previous_version_binary_can_be_downloaded(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $user = $this->userWithPermissions(['files.view', 'files.download']);
        $document = $this->createWordDocument($user, 'Expediente', 'v2');
        $oldPath = 'files/'.$user->id.'/expediente-v1.docx';
        Storage::disk('local')->put($oldPath, "PK\x03\x04".'borrador-inicial');

        $old = DocumentVersion::create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'file_path' => $oldPath,
            'file_size' => Storage::disk('local')->size($oldPath),
            'version_number' => 'v1',
            'change_summary' => 'Borrador inicial.',
        ]);

        $response = $this->actingAs($user)->get(route('document-versions.download', $old));

        $response->assertOk()
            ->assertDownload(strtolower($document->title).'.v1.docx');
    }

    public function test_create_word_creates_the_document_binary_and_first_version(): void
    {
        $user = $this->userWithPermissions(['files.view', 'docs.create']);

        $response = $this->actingAs($user)->post(route('documents.create-word'), [
            'title' => 'Carta de derivación',
        ]);

        $document = Document::query()->where('title', 'Carta de derivación')->firstOrFail();
        $response->assertRedirect(route('documents.history', $document));

        $this->assertDatabaseHas('document_versions', [
            'document_id' => $document->id,
            'version_number' => 'v1',
            'change_summary' => 'Documento inicial',
        ]);
        $this->assertNotNull($document->currentVersion);
        $this->assertNotNull($document->wordFile());
        Storage::disk('local')->assertExists($document->wordFile()->storage_path);
    }

    public function test_create_word_falls_back_to_root_when_folder_id_does_not_exist(): void
    {
        $user = $this->userWithPermissions(['files.view', 'docs.create']);

        $response = $this->actingAs($user)->post(route('documents.create-word'), [
            'title' => 'Carta sin carpeta',
            'folder_id' => 999999,
        ]);

        $document = Document::query()->where('title', 'Carta sin carpeta')->firstOrFail();
        $response->assertRedirect(route('documents.history', $document));

        $this->assertNotNull($document->wordFile());
        $this->assertNull($document->folder_id);
        $this->assertNull($document->wordFile()->folder_id);
    }

    public function test_link_word_links_an_orphan_word_file_to_a_document_and_redirects_to_history(): void
    {
        $user = $this->userWithPermissions(['files.view', 'docs.create']);
        $storagePath = 'files/'.$user->id.'/plantilla.docx';
        Storage::disk('local')->put($storagePath, "PK\x03\x04".'plantilla-docx');
        $file = File::create([
            'name' => 'plantilla',
            'original_name' => 'plantilla.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'storage_path' => $storagePath,
            'file_size' => Storage::disk('local')->size($storagePath),
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->post(route('files.link-word', $file));

        $file->refresh();
        $response->assertRedirect(route('documents.history', $file->document));
        $this->assertNotNull($file->document_id);
        $this->assertDatabaseHas('document_versions', [
            'document_id' => $file->document_id,
            'version_number' => 'v1',
            'change_summary' => 'Versión inicial del archivo subido',
        ]);
    }

    private function createWordDocument(User $user, string $title, string $versionNumber): Document
    {
        $storagePath = 'files/'.$user->id.'/'.strtolower(str_replace(' ', '-', $title)).'.docx';
        Storage::disk('local')->put($storagePath, "PK\x03\x04".'contenido-base');

        $document = Document::create([
            'title' => $title,
            'content' => '<p></p>',
            'user_id' => $user->id,
            'imported_from' => $title.'.docx',
        ]);

        $file = File::create([
            'name' => strtolower(str_replace(' ', '-', $title)),
            'original_name' => $title.'.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'storage_path' => $storagePath,
            'file_size' => Storage::disk('local')->size($storagePath),
            'user_id' => $user->id,
            'document_id' => $document->id,
        ]);

        $version = DocumentVersion::create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'file_path' => $file->storage_path,
            'file_size' => $file->file_size,
            'version_number' => $versionNumber,
            'change_summary' => 'Documento inicial',
        ]);

        $document->forceFill(['current_version_id' => $version->id])->saveQuietly();

        return $document;
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
}