<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentAnnotation;
use App\Models\DocumentVersion;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WordAddinApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['filesystems.default' => 'local']);
    }

    public function test_login_returns_a_bearer_token(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $response = $this->postJson('/api/addin/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']])
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::factory()->create(['email' => 'demo@example.com']);

        $this->postJson('/api/addin/login', [
            'email' => 'demo@example.com',
            'password' => 'incorrecta',
        ])->assertUnauthorized();
    }

    public function test_documents_are_listed_with_lock_state_and_current_version(): void
    {
        $user = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($user, 'Informe semanal', 'v2');
        $document->forceFill(['is_locked' => true, 'locked_by_id' => $user->id, 'locked_at' => now()])->saveQuietly();

        $response = $this->api($this->tokenFor($user))->getJson('/api/addin/documents');

        $response->assertOk()
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.id', $document->id)
            ->assertJsonPath('documents.0.current_version', 'v2')
            ->assertJsonPath('documents.0.is_locked', true)
            ->assertJsonPath('documents.0.locked_by.name', $user->name);
    }

    public function test_documents_list_includes_panel_created_documents_with_their_folder(): void
    {
        $user = $this->userWithPermissions(['files.view']);
        $folder = Folder::create(['name' => 'Contratos', 'user_id' => $user->id]);
        $plain = Document::create([
            'title' => 'Recién creado en el panel',
            'content' => '<p>Texto escrito en la plataforma.</p>',
            'folder_id' => $folder->id,
            'user_id' => $user->id,
        ]);

        $response = $this->api($this->tokenFor($user))->getJson('/api/addin/documents');

        $response->assertOk()
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.id', $plain->id)
            ->assertJsonPath('documents.0.folder_id', $folder->id)
            ->assertJsonPath('documents.0.folder_name', 'Contratos')
            ->assertJsonPath('documents.0.current_version', null)
            ->assertJsonPath('documents.0.file_name', null);
    }

    public function test_contents_navigates_folders_with_documents_files_and_breadcrumbs(): void
    {
        $user = $this->userWithPermissions(['files.view']);

        $proyectos = Folder::create(['name' => 'Proyectos', 'user_id' => $user->id]);
        $informes = Folder::create(['name' => 'Informes', 'user_id' => $user->id, 'parent_id' => $proyectos->id]);
        $documentoRaiz = $this->createWordDocument($user, 'Propuesta inicial', 'v2');
        $documentoRaiz->forceFill(['folder_id' => $proyectos->id])->saveQuietly();
        $documentoRaiz->wordFile()->forceFill(['folder_id' => $proyectos->id])->saveQuietly();
        $documentoHijo = $this->createWordDocument($user, 'Acta de reunión', 'v1');
        $documentoHijo->forceFill(['folder_id' => $informes->id])->saveQuietly();
        $documentoHijo->wordFile()->forceFill(['folder_id' => $informes->id])->saveQuietly();

        // Un archivo suelto (PDF) y un docx vinculado al documento Raiz: el
        // vinculado NO debe listarse como archivo porque ya es documento.
        File::create([
            'name' => 'manual',
            'original_name' => 'manual.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => 'files/manual.pdf',
            'file_size' => 1234,
            'user_id' => $user->id,
        ]);

        $rootList = $this->api($this->tokenFor($user))->getJson('/api/addin/folders');

        $rootList->assertOk()
            ->assertJsonPath('current_folder', null)
            ->assertJsonPath('breadcrumbs', [])
            ->assertJsonCount(1, 'folders')
            ->assertJsonPath('folders.0.name', 'Proyectos')
            ->assertJsonCount(0, 'documents')
            ->assertJsonCount(1, 'files')
            ->assertJsonPath('files.0.original_name', 'manual.pdf');

        $inside = $this->api($this->tokenFor($user))
            ->getJson('/api/addin/folders?folder_id='.$proyectos->id);

        $inside->assertOk()
            ->assertJsonPath('current_folder.name', 'Proyectos')
            ->assertJsonPath('breadcrumbs.0.name', 'Proyectos')
            ->assertJsonCount(1, 'folders')
            ->assertJsonPath('folders.0.name', 'Informes')
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.id', $documentoRaiz->id)
            ->assertJsonCount(0, 'files');

        $child = $this->api($this->tokenFor($user))
            ->getJson('/api/addin/folders?folder_id='.$informes->id);

        $child->assertOk()
            ->assertJsonCount(2, 'breadcrumbs')
            ->assertJsonPath('breadcrumbs.0.name', 'Proyectos')
            ->assertJsonPath('breadcrumbs.1.name', 'Informes')
            ->assertJsonCount(0, 'folders')
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.id', $documentoHijo->id);
    }

    public function test_checkout_refuses_to_fabricate_a_docx_for_a_document_with_web_edit_content(): void
    {
        $user = $this->userWithPermissions(['files.view']);
        $document = Document::create([
            'title' => 'Memorándum del panel',
            'content' => '<p>Contenido escrito en la plataforma.</p>',
            'user_id' => $user->id,
        ]);

        // Un documento sin binario pero con contenido del editor web NO debe
        // transformarse en un `.docx` fabricado (la conversión HTML→docx pierde
        // márgenes, encabezados, tabulaciones e imágenes WMF/EMF). Esa pieza
        // falsa se convertiría en "el original". Mejor 409 con instrucciones.
        $response = $this->api($this->tokenFor($user))
            ->postJson("/api/addin/documents/{$document->id}/checkout");

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Este documento solo tiene contenido del editor web y no conserva su archivo Word original. Para mantener el formato al 100 %, sube el archivo .docx original (Subir versión) y vuelve a abrirlo en Word.');

        $document->refresh();
        $this->assertNull($document->currentVersion?->version_number);
        $this->assertDatabaseCount('document_versions', 0);
    }

    public function test_checkout_generates_a_docx_for_a_blank_document_without_a_binary(): void
    {
        $user = $this->userWithPermissions(['files.view']);
        $document = Document::create([
            'title' => 'Memorándum del panel',
            'content' => '<p></p>',
            'user_id' => $user->id,
        ]);

        $response = $this->api($this->tokenFor($user))
            ->postJson("/api/addin/documents/{$document->id}/checkout");

        $response->assertOk()
            ->assertJsonPath('locked', true)
            ->assertJsonPath('title', $document->title)
            ->assertJsonPath('file_name', 'memorandum-del-panel.v1.docx');

        $binary = base64_decode($response->json('file_base64'));
        $this->assertStringStartsWith("PK\x03\x04", $binary);

        $document->refresh();
        $this->assertSame('v1', $document->currentVersion?->version_number);
        $this->assertDatabaseCount('document_versions', 1);
        $this->assertDatabaseCount('files', 1);
    }

    public function test_a_user_can_lock_a_document_and_another_gets_423(): void
    {
        $owner = $this->userWithPermissions(['files.view']);
        $other = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($owner, 'Caso clínico', 'v1');

        $this->api($this->tokenFor($owner))
            ->postJson("/api/addin/documents/{$document->id}/lock")
            ->assertOk()
            ->assertJson(['locked' => true]);

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'is_locked' => true,
            'locked_by_id' => $owner->id,
        ]);

        // La misma persona puede re-bloquear sin error (idempotente).
        $this->api($this->tokenFor($owner))
            ->postJson("/api/addin/documents/{$document->id}/lock")
            ->assertOk();

        // Otro usuario obtiene 423 Locked.
        $this->api($this->tokenFor($other))
            ->postJson("/api/addin/documents/{$document->id}/lock")
            ->assertStatus(423);
    }

    public function test_checkout_locks_the_document_and_returns_the_binary_as_base64(): void
    {
        $user = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($user, 'Informe trimestral', 'v1');
        $binary = "PK\x03\x04".'contenido-checkout';

        Storage::disk('local')->put($document->currentVersion->file_path, $binary);

        $response = $this->api($this->tokenFor($user))
            ->postJson("/api/addin/documents/{$document->id}/checkout");

        $response->assertOk()
            ->assertJsonPath('locked', true)
            ->assertJsonPath('document', $document->id)
            ->assertJsonPath('title', $document->title)
            ->assertJsonPath('file_name', Str::slug($document->title).'.v1.docx')
            ->assertJsonPath('file_base64', base64_encode($binary));

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'is_locked' => true,
            'locked_by_id' => $user->id,
        ]);
        $this->assertNotEmpty($response->json('file_base64'));
    }

    public function test_checkout_is_idempotent_for_the_same_user(): void
    {
        $user = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($user, 'Caso clínico 2', 'v1');

        $this->api($this->tokenFor($user))
            ->postJson("/api/addin/documents/{$document->id}/checkout")
            ->assertOk()
            ->assertJsonPath('locked', true);

        $this->api($this->tokenFor($user))
            ->postJson("/api/addin/documents/{$document->id}/checkout")
            ->assertOk()
            ->assertJsonPath('locked', true);
    }

    public function test_checkout_is_rejected_with_423_when_locked_by_another_user(): void
    {
        $owner = $this->userWithPermissions(['files.view']);
        $other = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($owner, 'Privado', 'v1');

        $this->api($this->tokenFor($owner))
            ->postJson("/api/addin/documents/{$document->id}/lock")
            ->assertOk();

        $response = $this->api($this->tokenFor($other))
            ->postJson("/api/addin/documents/{$document->id}/checkout");

        $response->assertStatus(423)
            ->assertJsonPath('locked_by', $owner->name)
            ->assertJsonMissingPath('file_base64');
    }

    public function test_download_is_forbidden_without_the_lock(): void
    {
        $owner = $this->userWithPermissions(['files.view']);
        $other = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($owner, 'Acta', 'v1');

        $this->api($this->tokenFor($other))
            ->get("/api/addin/documents/{$document->id}/download")
            ->assertStatus(423);

        $this->api($this->tokenFor($owner))
            ->postJson("/api/addin/documents/{$document->id}/lock");

        $this->api($this->tokenFor($owner))
            ->get("/api/addin/documents/{$document->id}/download")
            ->assertOk()
            ->assertDownload(strtolower($document->title).'.v1.docx');
    }

    public function test_upload_requires_change_notes_and_the_lock(): void
    {
        $owner = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($owner, 'Informe', 'v1');

        $this->api($this->tokenFor($owner))
            ->postJson("/api/addin/documents/{$document->id}/lock")
            ->assertOk();

        // Sin anotaciones → 422 (validación JSON, como la del Add-in).
        $this->api($this->tokenFor($owner))
            ->postJson("/api/addin/documents/{$document->id}/upload", [
                'change_notes' => '',
                'file' => UploadedFile::fake()->createWithContent('informe.docx', "PK\x03\x04".'nuevo-contenido'),
            ])
            ->assertStatus(422);

        // Sin bloqueo → 423.
        $this->api($this->tokenFor($owner))
            ->postJson("/api/addin/documents/{$document->id}/unlock")
            ->assertOk();

        $this->api($this->tokenFor($owner))
            ->postJson("/api/addin/documents/{$document->id}/upload", [
                'change_notes' => 'Corregí la sección 3.',
                'file' => UploadedFile::fake()->createWithContent('informe.docx', "PK\x03\x04".'nuevo-contenido'),
            ])
            ->assertStatus(423);
    }

    public function test_upload_saves_the_binary_and_increments_the_version(): void
    {
        $user = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($user, 'Expediente', 'v1');

        $this->api($this->tokenFor($user))
            ->postJson("/api/addin/documents/{$document->id}/lock")
            ->assertOk();

        $this->api($this->tokenFor($user))
            ->post("/api/addin/documents/{$document->id}/upload", [
                'change_notes' => 'Agregué las conclusiones.',
                'file' => UploadedFile::fake()->createWithContent('expediente.docx', "PK\x03\x04".'contenido-v2'),
            ])
            ->assertOk()
            ->assertJsonPath('version.version_number', 'v2');

        $this->assertDatabaseCount('document_versions', 2);
        $this->assertDatabaseHas('document_versions', [
            'document_id' => $document->id,
            'version_number' => 'v2',
            'change_summary' => 'Agregué las conclusiones.',
            'user_id' => $user->id,
        ]);

        $document->refresh();
        $this->assertSame('v2', $document->currentVersion?->version_number);

        // El archivo vinculado apunta al binario más reciente y tiene su versión espejo.
        $linked = $document->wordFile();
        $this->assertNotNull($linked);
        $this->assertDatabaseHas('files', ['id' => $linked->id, 'storage_path' => $document->currentVersion->file_path]);
        $this->assertDatabaseHas('file_versions', ['file_id' => $linked->id, 'version' => 1]);
    }

    public function test_upload_refreshes_the_platform_html_preview_from_the_uploaded_docx(): void
    {
        $user = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($user, 'Memoria final', 'v1');

        $this->api($this->tokenFor($user))
            ->postJson("/api/addin/documents/{$document->id}/lock")
            ->assertOk();

        $buffer = $this->docxBuffer('Nuevo texto escrito desde Word.');

        $this->api($this->tokenFor($user))
            ->post("/api/addin/documents/{$document->id}/upload", [
                'change_notes' => 'Reescribí la sección 2.',
                'file' => UploadedFile::fake()->createWithContent('memoria.docx', $buffer),
            ])
            ->assertOk()
            ->assertJsonPath('version.version_number', 'v2');

        $document->refresh();
        $this->assertStringContainsString('Nuevo texto escrito desde Word.', (string) $document->content);
    }

    public function test_unlock_only_lets_the_lock_owner_release_it(): void
    {
        $owner = $this->userWithPermissions(['files.view']);
        $other = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($owner, 'Contrato', 'v1');

        $this->api($this->tokenFor($owner))->postJson("/api/addin/documents/{$document->id}/lock")->assertOk();

        $this->api($this->tokenFor($other))
            ->postJson("/api/addin/documents/{$document->id}/unlock")
            ->assertForbidden();

        $this->api($this->tokenFor($owner))
            ->postJson("/api/addin/documents/{$document->id}/unlock")
            ->assertOk()
            ->assertJson(['unlocked' => true]);

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'is_locked' => false,
            'locked_by_id' => null,
        ]);
    }

    public function test_history_returns_versions_with_author_and_notes(): void
    {
        $user = $this->userWithPermissions(['files.view']);
        $commenter = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($user, 'Memoria', 'v1');

        $annotation = DocumentAnnotation::create([
            'document_version_id' => $document->current_version_id,
            'user_id' => $commenter->id,
            'comment' => 'Revisa el párrafo del presupuesto.',
        ]);

        $response = $this->api($this->tokenFor($user))->getJson("/api/addin/documents/{$document->id}/history");

        $response->assertOk()
            ->assertJsonPath('versions.0.version_number', 'v1')
            ->assertJsonPath('versions.0.user.name', $user->name)
            ->assertJsonPath('versions.0.change_summary', 'Documento inicial')
            ->assertJsonCount(1, 'versions.0.annotations')
            ->assertJsonPath('versions.0.annotations.0.comment', $annotation->comment)
            ->assertJsonPath('versions.0.annotations.0.user.id', $commenter->id);
    }

    public function test_a_stale_lock_is_released_and_taken_over_on_checkout(): void
    {
        $owner = $this->userWithPermissions(['files.view']);
        $other = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($owner, 'Informe médico', 'v1');
        $document->forceFill([
            'is_locked' => true,
            'locked_by_id' => $owner->id,
            'locked_at' => now()->subHours(230),
        ])->saveQuietly();

        $response = $this->api($this->tokenFor($other))
            ->postJson("/api/addin/documents/{$document->id}/checkout");

        $response->assertOk()
            ->assertJsonPath('locked', true)
            ->assertJsonPath('document', $document->id);

        $document->refresh();
        $this->assertTrue($document->is_locked);
        $this->assertSame($other->id, $document->locked_by_id);
    }

    public function test_a_fresh_lock_by_another_user_still_blocks_checkout_even_if_same_user_tries_again(): void
    {
        $owner = $this->userWithPermissions(['files.view']);
        $other = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($owner, 'Informe vigente', 'v1');
        $document->forceFill([
            'is_locked' => true,
            'locked_by_id' => $owner->id,
            'locked_at' => now(),
        ])->saveQuietly();

        $this->api($this->tokenFor($other))
            ->postJson("/api/addin/documents/{$document->id}/checkout")
            ->assertStatus(423);

        $document->refresh();
        $this->assertSame($owner->id, $document->locked_by_id);
    }

    public function test_platform_uploaded_word_file_can_be_linked_from_the_addin_and_checked_out(): void
    {
        $user = $this->userWithPermissions(['files.view', 'docs.create']);
        $storagePath = 'files/'.$user->id.'/plantilla.docx';
        Storage::disk('local')->put($storagePath, $this->docxBuffer('Propuesta de contrato'));
        $file = File::create([
            'name' => 'plantilla',
            'original_name' => 'plantilla.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'storage_path' => $storagePath,
            'file_size' => Storage::disk('local')->size($storagePath),
            'user_id' => $user->id,
        ]);

        $response = $this->api($this->tokenFor($user))->postJson('/api/addin/files/'.$file->id.'/link');

        $response->assertOk()->assertJsonStructure(['document' => ['id', 'title', 'current_version']]);

        $document = Document::findOrFail($response->json('document.id'));
        $file->refresh();
        $this->assertSame($document->id, $file->document_id);
        $this->assertSame('v1', $document->currentVersion->version_number);
        $this->assertDatabaseHas('document_versions', [
            'document_id' => $document->id,
            'version_number' => 'v1',
            'change_summary' => 'Versión inicial del archivo subido',
        ]);

        $checkout = $this->api($this->tokenFor($user))
            ->postJson('/api/addin/documents/'.$document->id.'/checkout');

        $checkout->assertOk()->assertJsonPath('file_base64', base64_encode(Storage::disk('local')->get($storagePath)));
    }

    public function test_linking_an_already_linked_file_returns_the_same_document(): void
    {
        $user = $this->userWithPermissions(['files.view', 'docs.create']);
        $document = $this->createWordDocument($user, 'Acta ya vinculada', 'v1');

        $response = $this->api($this->tokenFor($user))
            ->postJson('/api/addin/files/'.$document->wordFile()->id.'/link');

        $response->assertOk()->assertJsonPath('document.id', $document->id);
        $this->assertSame(1, Document::count());
        $this->assertSame(1, DocumentVersion::count());
    }

    public function test_link_file_requires_docs_create_permission(): void
    {
        $user = $this->userWithPermissions(['files.view']);
        $storagePath = 'files/'.$user->id.'/solo-ver.docx';
        Storage::disk('local')->put($storagePath, $this->docxBuffer('solo lectura'));
        $file = File::create([
            'name' => 'solo-ver',
            'original_name' => 'solo-ver.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'storage_path' => $storagePath,
            'file_size' => Storage::disk('local')->size($storagePath),
            'user_id' => $user->id,
        ]);

        $this->api($this->tokenFor($user))
            ->postJson('/api/addin/files/'.$file->id.'/link')
            ->assertForbidden();

        $file->refresh();
        $this->assertNull($file->document_id);
    }

    public function test_heartbeat_renews_the_lock_and_rejects_other_users(): void
    {
        $owner = $this->userWithPermissions(['files.view']);
        $other = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($owner, 'Informe activo', 'v1');
        $document->forceFill([
            'is_locked' => true,
            'locked_by_id' => $owner->id,
            'locked_at' => now()->subMinutes(30),
        ])->saveQuietly();
        $updatedAtBefore = $document->fresh()->updated_at;

        $this->api($this->tokenFor($owner))
            ->postJson("/api/addin/documents/{$document->id}/heartbeat")
            ->assertOk()
            ->assertJson(['ok' => true, 'locked' => true]);

        $document->refresh();
        $this->assertTrue($document->locked_at->greaterThanOrEqualTo(now()->subSeconds(30)));
        // El latido NO debe tocar updated_at: la firma del Explorador no se altera.
        $this->assertSame($updatedAtBefore->toISOString(), $document->updated_at->toISOString());

        // Un tercero no puede renovar ni un bloqueo ajeno.
        $this->api($this->tokenFor($other))
            ->postJson("/api/addin/documents/{$document->id}/heartbeat")
            ->assertStatus(423);
    }

    public function test_heartbeat_on_a_freed_document_returns_409(): void
    {
        $user = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($user, 'Sin bloqueo', 'v1');

        $this->api($this->tokenFor($user))
            ->postJson("/api/addin/documents/{$document->id}/heartbeat")
            ->assertStatus(409);
    }

    public function test_contents_releases_expired_locks_via_polling(): void
    {
        $owner = $this->userWithPermissions(['files.view']);
        $other = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($owner, 'Lock caducado', 'v1');
        $document->forceFill([
            'is_locked' => true,
            'locked_by_id' => $owner->id,
            'locked_at' => now()->subHours(2),
        ])->saveQuietly();

        $response = $this->api($this->tokenFor($other))->getJson('/api/addin/folders');

        $response->assertOk()->assertJsonPath('documents.0.is_locked', false);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'is_locked' => false,
            'locked_by_id' => null,
        ]);
    }

    public function test_logout_releases_the_users_locks_and_revokes_the_token(): void
    {
        $owner = $this->userWithPermissions(['files.view']);
        $first = $this->createWordDocument($owner, 'Doc uno', 'v1');
        $second = $this->createWordDocument($owner, 'Doc dos', 'v1');
        $first->forceFill(['is_locked' => true, 'locked_by_id' => $owner->id, 'locked_at' => now()])->saveQuietly();
        $second->forceFill(['is_locked' => true, 'locked_by_id' => $owner->id, 'locked_at' => now()])->saveQuietly();
        $token = $this->tokenFor($owner);

        $this->api($token)
            ->postJson('/api/addin/logout')
            ->assertOk()
            ->assertJsonPath('released_locks', 2);

        $this->assertDatabaseHas('documents', ['id' => $first->id, 'is_locked' => false]);
        $this->assertDatabaseHas('documents', ['id' => $second->id, 'is_locked' => false]);

        // El token revocado ya no autoriza nuevas peticiones.
        $this->api($token)->getJson('/api/addin/documents')->assertUnauthorized();
    }

    public function test_two_simultaneous_uploads_do_not_duplicate_version_numbers(): void
    {
        $user = $this->userWithPermissions(['files.view']);
        $document = $this->createWordDocument($user, 'Simultáneo', 'v1');
        $document->forceFill(['is_locked' => true, 'locked_by_id' => $user->id, 'locked_at' => now()])->saveQuietly();

        // Dos "instancias stale" del mismo documento (simula dos subidas en
        // paralelo): cada una calcula su versión contra la fila bloqueada.
        $staleA = Document::query()->find($document->id);
        $staleB = Document::query()->find($document->id);
        $uploader = app(\App\Services\DocumentVersionUploader::class);

        $a = $uploader->upload($staleA, UploadedFile::fake()->createWithContent('a.docx', "PK\x03\x04".'a'), $user->id, 'Primera');
        $b = $uploader->upload($staleB, UploadedFile::fake()->createWithContent('b.docx', "PK\x03\x04".'b'), $user->id, 'Segunda');

        $this->assertDatabaseCount('document_versions', 3);
        $numbers = $document->versions()->pluck('version_number')->values()->all();
        $this->assertSame(['v3', 'v2', 'v1'], $numbers);
        $this->assertSame($b->id, $document->fresh()->current_version_id);
        $versionA = $a->version_number;
        $versionB = $b->version_number;
        $this->assertNotSame($versionA, $versionB);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /**
     * Hace la petición con un Bearer token. En los tests el contenedor es
     * compartido entre peticiones y el guard de Sanctum cachea al usuario
     * autenticado; resecamos los guards para que cada petición use su token.
     */
    private function api(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /**
     * Crea un documento Word con un binario vinculado y su versión inicial.
     */
    private function createWordDocument(User $user, string $title, string $versionNumber = 'v1'): Document
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

    private function docxText(string $path): string
    {
        $phpWord = IOFactory::load($path);
        $text = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if ($element instanceof \PhpOffice\PhpWord\Element\Text) {
                    $text .= ' '.$element->getText();
                } elseif ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                    foreach ($element->getElements() as $run) {
                        if ($run instanceof \PhpOffice\PhpWord\Element\Text) {
                            $text .= ' '.$run->getText();
                        }
                    }
                }
            }
        }

        return trim($text);
    }
}