<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PlatformVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['filesystems.default' => 'local']);
    }

    public function test_platform_can_upload_a_new_version_with_renamed_file(): void
    {
        $user = $this->userWithPermissions(['files.view', 'files.upload']);
        $document = $this->createWordDocument($user, 'Informe presupuesto', 'v1');

        $buffer = $this->docxBuffer('Presupuesto final corregido.');

        $response = $this->actingAs($user)->post(route('documents.versions.store', $document), [
            'change_notes' => 'Presupuesto final.',
            'file' => UploadedFile::fake()->createWithContent('presupuesto-2026-final.docx', $buffer),
        ]);

        $response->assertOk()
            ->assertJsonPath('saved', true)
            ->assertJsonPath('version.version_number', 'v2');

        $document->refresh();
        $this->assertSame(2, $document->versions()->count());
        $this->assertStringContainsString('Presupuesto final corregido.', (string) $document->content);

        $linked = $document->wordFile();
        $this->assertNotNull($linked);
        $this->assertSame('presupuesto-2026-final.docx', $linked->original_name);
        $v2 = $document->versions()->where('version_number', 'v2')->firstOrFail();
        $this->assertSame($v2->file_path, $linked->storage_path);
        $this->assertSame(1, $linked->versions()->count());
        $this->assertSame('platform', $linked->versions()->first()->source);
        $this->assertSame(1, $linked->versions()->first()->version);

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'is_locked' => false,
            'locked_by_id' => null,
            'locked_at' => null,
        ]);
    }

    public function test_platform_cannot_upload_a_version_while_another_user_holds_a_fresh_lock(): void
    {
        $owner = $this->userWithPermissions(['files.view', 'files.upload']);
        $other = $this->userWithPermissions(['files.view', 'files.upload']);
        $document = $this->createWordDocument($owner, 'Contrato en edición', 'v1');
        $document->forceFill([
            'is_locked' => true,
            'locked_by_id' => $other->id,
            'locked_at' => now(),
        ])->saveQuietly();

        $response = $this->actingAs($owner)->post(route('documents.versions.store', $document), [
            'change_notes' => 'Intento mientras está bloqueado por otro.',
            'file' => UploadedFile::fake()->createWithContent('contrato.docx', $this->docxBuffer('X')),
        ]);

        $response->assertStatus(409)
            ->assertJson(['message' => 'El documento está siendo editado en Word por '.$other->name.'. Libera su bloqueo antes de subir una versión manual.']);

        $document->refresh();
        $this->assertSame(1, $document->versions()->count());
        $this->assertTrue($document->is_locked);
        $this->assertSame($other->id, $document->locked_by_id);
    }

    public function test_platform_upload_is_allowed_while_the_current_user_holds_the_lock_modal_flow(): void
    {
        $owner = $this->userWithPermissions(['files.view', 'files.upload']);
        $document = $this->createWordDocument($owner, 'Contrato en edición propia', 'v1');
        $document->forceFill([
            'is_locked' => true,
            'locked_by_id' => $owner->id,
            'locked_at' => now(),
        ])->saveQuietly();

        $response = $this->actingAs($owner)->post(route('documents.versions.store', $document), [
            'change_notes' => 'Subida desde el modal de "Modificar".',
            'file' => UploadedFile::fake()->createWithContent('contrato.docx', $this->docxBuffer('Texto edición modal.')),
        ]);

        $response->assertOk()->assertJsonPath('version.version_number', 'v2');

        $document->refresh();
        $this->assertSame(2, $document->versions()->count());
        $this->assertFalse($document->is_locked);
        $this->assertNull($document->locked_by_id);
    }

    public function test_platform_upload_takes_over_a_stale_lock_and_never_leaves_it_locked(): void
    {
        $owner = $this->userWithPermissions(['files.view', 'files.upload']);
        $uploader = $this->userWithPermissions(['files.view', 'files.upload']);
        $document = $this->createWordDocument($owner, 'Memoria caducada', 'v1');
        $document->forceFill([
            'is_locked' => true,
            'locked_by_id' => $owner->id,
            'locked_at' => now()->subMinutes(240),
        ])->saveQuietly();

        $response = $this->actingAs($uploader)->post(route('documents.versions.store', $document), [
            'change_notes' => 'Actualización manual.',
            'file' => UploadedFile::fake()->createWithContent('memoria-revisada.docx', $this->docxBuffer('Versión corregida.')),
        ]);

        $response->assertOk()
            ->assertJsonPath('version.version_number', 'v2');

        $document->refresh();
        $this->assertStringContainsString('Versión corregida.', (string) $document->content);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'is_locked' => false,
            'locked_by_id' => null,
            'locked_at' => null,
        ]);
    }

    public function test_platform_upload_releases_the_lock_even_if_the_upload_fails(): void
    {
        $user = $this->userWithPermissions(['files.view', 'files.upload']);
        $document = $this->createWordDocument($user, 'Fallará la subida', 'v1');

        $response = $this->actingAs($user)->post(route('documents.versions.store', $document), [
            'change_notes' => '',
            'file' => UploadedFile::fake()->createWithContent('invalido.docx', 'no-es-un-docx'),
        ]);

        $response->assertStatus(422);

        $document->refresh();
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'is_locked' => false,
            'locked_by_id' => null,
            'locked_at' => null,
        ]);
    }

    public function test_platform_upload_requires_permission(): void
    {
        $user = $this->userWithPermissions([]);
        $document = $this->createWordDocument($user, 'Sin permiso', 'v1');

        $this->actingAs($user)
            ->post(route('documents.versions.store', $document), [
                'file' => UploadedFile::fake()->createWithContent('sin-permiso.docx', $this->docxBuffer('X')),
            ])
            ->assertForbidden();
    }

    public function test_explorer_state_returns_signature_and_live_lock_states(): void
    {
        $user = $this->userWithPermissions(['files.view']);
        $folder = Folder::create(['name' => 'Clínica', 'user_id' => $user->id]);
        $document = $this->createWordDocument($user, 'Historia clínica', 'v1', $folder->id);
        $document->forceFill(['is_locked' => true, 'locked_by_id' => $user->id, 'locked_at' => now()])->saveQuietly();

        $response = $this->actingAs($user)->getJson(route('explorer.state', ['folder_id' => $folder->id]));

        $response->assertOk()
            ->assertJsonStructure(['signature', 'documents' => [['id', 'is_locked', 'locked_by', 'locked_by_id', 'locked_at']]])
            ->assertJsonPath('documents.0.id', $document->id)
            ->assertJsonPath('documents.0.is_locked', true)
            ->assertJsonPath('documents.0.locked_by', $user->name);

        $first = $response->json('signature');
        $this->assertNotSame($first, '<<vacio>>');

        $document->forceFill(['is_locked' => false, 'locked_by_id' => null, 'locked_at' => null])->saveQuietly();
        $second = $this->actingAs($user)->getJson(route('explorer.state', ['folder_id' => $folder->id]))->json('signature');
        $this->assertNotSame($first, $second);
        $this->actingAs($user)->getJson(route('explorer.state', ['folder_id' => $folder->id]))
            ->assertJsonPath('documents.0.is_locked', false);
    }

    private function createWordDocument(User $user, string $title, string $versionNumber = 'v1', ?int $folderId = null): Document
    {
        $storagePath = 'files/'.$user->id.'/'.strtolower(str_replace(' ', '-', $title)).'.docx';
        Storage::disk('local')->put($storagePath, "PK\x03\x04".'contenido-base');

        $document = Document::create([
            'title' => $title,
            'content' => '<p></p>',
            'folder_id' => $folderId,
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
            'file_path' => $storagePath,
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

    private function docxBuffer(string $text): string
    {
        $phpWord = new PhpWord;
        $phpWord->addSection()->addText($text);
        $tempPath = tempnam(sys_get_temp_dir(), 'pdoc');
        IOFactory::createWriter($phpWord, 'Word2007')->save($tempPath);
        $buffer = (string) file_get_contents($tempPath);
        @unlink($tempPath);

        return $buffer;
    }
}