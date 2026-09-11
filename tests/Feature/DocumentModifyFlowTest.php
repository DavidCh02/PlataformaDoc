<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\File;
use App\Models\User;
use App\Services\DocumentModifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DocumentModifyFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['filesystems.default' => 'local']);
    }

    public function test_web_modify_downloads_the_docx_locks_it_and_injects_the_metadata(): void
    {
        $user = $this->userWithPermissions(['files.view', 'files.download']);
        $document = $this->createWordDocument($user, 'Informe para editar', 'v1', 'Contenido original intacto.');

        $response = $this->actingAs($user)->post('/documents/'.$document->id.'/modify');

        $response->assertOk()->assertDownload('informe-para-editar.docx');
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'is_locked' => true,
            'locked_by_id' => $user->id,
            'editing_cancelled_at' => null,
        ]);

        // El binario origen queda intacto (fidelidad 100 %): nose regenera nada.
        $this->assertSame(
            Storage::disk('local')->size($document->currentVersion->file_path),
            $document->fresh()->currentVersion->file_size,
        );
    }

    public function test_web_modify_can_be_repeated_by_the_lock_owner_and_returns_a_readable_docx(): void
    {
        $user = $this->userWithPermissions(['files.view', 'files.download']);
        $document = $this->createWordDocument($user, 'Acta reunión', 'v1', 'Contenido del acta.');

        $this->actingAs($user)->post('/documents/'.$document->id.'/modify')->assertOk();
        $second = $this->actingAs($user)->post('/documents/'.$document->id.'/modify');

        $second->assertOk();
        $download = $second->baseResponse;
        $this->assertTrue($download->isSuccessful());
    }

    public function test_web_modify_is_rejected_with_423_when_locked_by_another_user(): void
    {
        $owner = $this->userWithPermissions(['files.view', 'files.download']);
        $other = $this->userWithPermissions(['files.view', 'files.download']);
        $document = $this->createWordDocument($owner, 'Con exclusiva', 'v1', 'Texto.');

        $this->actingAs($owner)->post('/documents/'.$document->id.'/modify')->assertOk();

        $response = $this->actingAs($other)->post('/documents/'.$document->id.'/modify');

        $response->assertStatus(423)->assertJsonPath('locked_by', $owner->name);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'locked_by_id' => $owner->id]);
    }

    public function test_web_modify_rejects_docs_with_415_and_cancels_the_lock_claim(): void
    {
        $user = $this->userWithPermissions(['files.view', 'files.download', 'docs.edit_realtime', 'files.upload']);
        $document = $this->createWordDocument($user, 'Legacy.doc', 'v1');
        $storagePath = 'files/'.$user->id.'/legacy.doc';
        Storage::disk('local')->put($storagePath, '%PDF-o-doc-falso');
        $document->currentVersion->forceFill(['file_path' => $storagePath])->saveQuietly();

        $response = $this->actingAs($user)->post('/documents/'.$document->id.'/modify');

        $response->assertStatus(415);
        // El documento no queda bloqueado colgando tras un error de formato.
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'is_locked' => false]);
    }

    public function test_web_modify_response_carries_the_injected_custom_property(): void
    {
        $user = $this->userWithPermissions(['files.view', 'files.download']);
        $document = $this->createWordDocument($user, 'Con metadatos', 'v1', 'Contenido.');
        $disk = config('filesystems.default');
        $original = (string) Storage::disk($disk)->get($document->currentVersion->file_path);

        $response = $this->actingAs($user)->post('/documents/'.$document->id.'/modify');
        $downloaded = $response->baseResponse->getFile()->getContent();

        $this->assertDocumentHasProperty($downloaded, $document->id);
        $this->assertNotSame($original, $downloaded);

        // El .docx con metadatos sigue siendo legible para PhpWord (está bien formado).
        $temp = tempnam(sys_get_temp_dir(), 'pdoc');
        file_put_contents($temp, $downloaded);
        $phpWord = IOFactory::load($temp);
        @unlink($temp);
        $this->assertCount(1, $phpWord->getSections());
    }

    public function test_the_platform_modal_can_upload_a_version_while_the_lock_is_held_by_the_same_user(): void
    {
        $user = $this->userWithPermissions(['files.view', 'files.download', 'docs.edit_realtime', 'files.upload']);
        $document = $this->createWordDocument($user, 'Para subir desde modal', 'v1', 'Texto.');
        $this->actingAs($user)->post('/documents/'.$document->id.'/modify')->assertOk();

        $response = $this->actingAs($user)->postJson('/documents/'.$document->id.'/versions', [
            'change_notes' => 'Cambios desde el modal de la plataforma.',
            'file' => UploadedFile::fake()->createWithContent('para-subir.docx', $this->docxBuffer('Texto editado en Word.')),
        ]);

        $response->assertOk()->assertJsonPath('version.version_number', 'v2');
        $document->refresh();
        $this->assertSame('v2', $document->currentVersion->version_number);
    }

    public function test_web_cancel_releases_the_lock_and_marks_the_edition_as_cancelled(): void
    {
        $user = $this->userWithPermissions(['files.view', 'files.download']);
        $document = $this->createWordDocument($user, 'A cancelar', 'v1', 'Texto.');
        $this->actingAs($user)->post('/documents/'.$document->id.'/modify')->assertOk();

        $response = $this->actingAs($user)->postJson('/documents/'.$document->id.'/modify/cancel');

        $response->assertOk()->assertJson(['cancelled' => true]);
        $document->refresh();
        $this->assertFalse($document->is_locked);
        $this->assertNull($document->locked_by_id);
        $this->assertNotNull($document->editing_cancelled_at);
    }

    public function test_addin_upload_is_rejected_after_a_platform_cancel_with_the_exact_message(): void
    {
        $user = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($user, 'Cancelada', 'v1', 'Texto.');
        $document->forceFill([
            'is_locked' => true,
            'locked_by_id' => $user->id,
            'locked_at' => now(),
            'editing_cancelled_at' => now()->subMinute(),
        ])->saveQuietly();

        $response = $this->api($this->tokenFor($user))
            ->post('/api/addin/documents/'.$document->id.'/upload', [
                'change_notes' => 'Intento de guardar.',
                'file' => UploadedFile::fake()->createWithContent('cancelada.docx', "PK\x03\x04".'nuevo'),
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('message', 'La edición fue cancelada desde la plataforma. Cierra este documento y usa "Modificar" de nuevo en el Explorador.');
        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_addin_heartbeat_returns_409_with_cancel_message_after_a_platform_cancel(): void
    {
        $user = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($user, 'Con cancelación', 'v1', 'Texto.');
        $document->forceFill([
            'is_locked' => true,
            'locked_by_id' => $user->id,
            'locked_at' => now(),
            'editing_cancelled_at' => now()->subMinute(),
        ])->saveQuietly();

        $response = $this->api($this->tokenFor($user))
            ->postJson('/api/addin/documents/'.$document->id.'/heartbeat');

        $response->assertStatus(409)
            ->assertJsonPath('message', 'La edición fue cancelada desde la plataforma. Cierra este documento y usa "Modificar" de nuevo en el Explorador.');
    }

    public function test_addin_session_reports_active_only_for_my_live_non_cancelled_lock(): void
    {
        $owner = $this->userWithPermissions(['files.view']);
        $other = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($owner, 'Mi sesión', 'v1', 'Texto.');
        $document->forceFill(['is_locked' => true, 'locked_by_id' => $owner->id, 'locked_at' => now()])->saveQuietly();

        $mine = $this->api($this->tokenFor($owner))->getJson('/api/addin/documents/'.$document->id.'/session');
        $mine->assertOk()
            ->assertJsonPath('session.active', true)
            ->assertJsonPath('session.locked_by_me', true)
            ->assertJsonPath('session.cancelled', false);

        $theirs = $this->api($this->tokenFor($other))->getJson('/api/addin/documents/'.$document->id.'/session');
        $theirs->assertOk()
            ->assertJsonPath('session.active', false)
            ->assertJsonPath('session.locked_by_me', false);

        $document->forceFill(['editing_cancelled_at' => now()])->saveQuietly();
        $afterCancel = $this->api($this->tokenFor($owner))->getJson('/api/addin/documents/'.$document->id.'/session');
        $afterCancel->assertOk()
            ->assertJsonPath('session.active', false)
            ->assertJsonPath('session.cancelled', true);
    }

    public function test_addin_modify_locks_and_returns_the_binary_with_metadata_embedded(): void
    {
        $user = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($user, 'Desde el Add-in', 'v1', 'Texto para el add-in.');

        $response = $this->api($this->tokenFor($user))
            ->postJson('/api/addin/documents/'.$document->id.'/modify');

        $response->assertOk()
            ->assertJsonPath('locked', true)
            ->assertJsonPath('document', $document->id)
            ->assertJsonPath('file_name', Str::slug($document->title).'.v1.docx');

        $binary = base64_decode($response->json('file_base64'));
        $this->assertStringStartsWith("PK\x03\x04", $binary);
        $this->assertDocumentHasProperty($binary, $document->id);

        $document->refresh();
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'is_locked' => true,
            'locked_by_id' => $user->id,
        ]);
    }

    public function test_document_modifier_updates_an_existing_property_without_duplicating_it(): void
    {
        $modifier = app(DocumentModifier::class);
        $document = $this->createWordDocument(null, 'Doble', 'v1', 'Texto.');

        $first = $modifier->injectMetadata($this->docxBuffer('Texto.'), 1);
        $second = $modifier->injectMetadata($first, 2);

        $this->assertDocumentHasProperty($first, 1);
        $this->assertDocumentHasProperty($second, 2);

        $temp = tempnam(sys_get_temp_dir(), 'pdoc');
        file_put_contents($temp, $second);
        $zip = new \ZipArchive;
        $zip->open($temp);
        $custom = $zip->getFromName('docProps/custom.xml');
        $zip->close();
        @unlink($temp);

        $this->assertSame(1, preg_match_all('/name="plataforma_doc_id"/', $custom));
        $this->assertStringContainsString('>2<', (string) $custom);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function assertDocumentHasProperty(string $binary, int $documentId): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'pdoc');
        file_put_contents($temp, $binary);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($temp) === true, 'El .docx devuelto no es un ZIP válido.');
        $custom = $zip->getFromName('docProps/custom.xml');
        $rootRels = $zip->getFromName('_rels/.rels');
        $types = $zip->getFromName('[Content_Types].xml');
        $zip->close();
        @unlink($temp);

        $this->assertNotNull($custom, 'Falta docProps/custom.xml.');
        $this->assertStringContainsString('plataforma_doc_id', (string) $custom);
        $this->assertStringContainsString('>'.$documentId.'<', (string) $custom);
        // Word expone la propiedad solo si la relación de paquete existe en
        // _rels/.rels (es la que utiliza la API de Office para leerla).
        $this->assertNotNull($rootRels, 'Falta _rels/.rels del paquete.');
        $this->assertStringContainsString('relationships/custom-properties', (string) $rootRels);
        $this->assertStringContainsString('Target="docProps/custom.xml"', (string) $rootRels);
        $this->assertStringContainsString('custom-properties+xml', (string) $types);
    }

    public function test_uploading_a_docx_from_the_explorer_auto_links_it_to_an_editable_document(): void
    {
        $user = $this->userWithPermissions(['files.upload', 'files.view', 'files.download', 'docs.create']);

        $this->actingAs($user)->post(route('files.store'), [
            'file' => UploadedFile::fake()->createWithContent('expediente.docx', $this->docxBuffer('Expediente clínico.')),
        ])->assertRedirect();

        // El archivo quedó vinculado a un documento y se registró v1 con el
        // binario original (fidelidad 100 %).
        $file = File::query()->latest('id')->firstOrFail();
        $this->assertNotNull($file->document_id);
        $document = $file->document;
        $this->assertNotNull($document);
        $this->assertDatabaseHas('document_versions', [
            'document_id' => $document->id,
            'version_number' => 'v1',
            'file_path' => $file->storage_path,
        ]);
        $this->assertSame($document->current_version_id, DocumentVersion::query()->where('document_id', $document->id)->value('id'));

        // El Explorador lo muestra UNA SOLA VEZ como documento editable (con
        // Modificar / Subir versión / Historial), sin la fila de archivo suelto.
        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Explorer')
                ->where('files', [])
                ->has('documents', 1)
                ->where('documents.0.id', $document->id)
                ->where('documents.0.title', 'expediente')
                ->where('documents.0.linked_file.original_name', 'expediente.docx'));

        // Y el flujo "Modificar" funciona sobre ese documento automáticamente.
        $this->actingAs($user)->post(route('documents.modify', $document))
            ->assertOk()
            ->assertDownload('expediente.docx');
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'is_locked' => true,
            'locked_by_id' => $user->id,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function api(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
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

    private function createWordDocument(?User $user, string $title, string $versionNumber = 'v1', ?string $text = null): Document
    {
        $user ??= User::factory()->create();
        $storagePath = 'files/'.$user->id.'/'.strtolower(str_replace(' ', '-', $title)).'.docx';
        $buffer = $text === null ? "PK\x03\x04".'contenido-base' : $this->docxBuffer($text);
        Storage::disk('local')->put($storagePath, $buffer);

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
            'file_size' => strlen($buffer),
            'user_id' => $user->id,
            'document_id' => $document->id,
        ]);

        $version = DocumentVersion::create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'file_path' => $storagePath,
            'file_size' => $file->file_size,
            'version_number' => $versionNumber,
            'change_summary' => 'Versión inicial del archivo subido',
        ]);

        $document->forceFill(['current_version_id' => $version->id])->saveQuietly();

        return $document;
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