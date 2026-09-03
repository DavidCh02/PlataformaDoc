<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private function ensurePermissions(array $names): void
    {
        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
    }

    public function test_uploading_a_file_is_audited_with_user_and_ip(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $user = User::factory()->create();
        $this->ensurePermissions(['files.upload']);
        $user->givePermissionTo('files.upload');

        $this->actingAs($user)->post(route('files.store'), [
            'file' => UploadedFile::fake()->create('informe.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

        $file = File::query()->latest('id')->firstOrFail();
        $log = AuditLog::query()->where('action', 'file.upload')->latest('id')->firstOrFail();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('127.0.0.1', $log->ip_address);
        $this->assertSame($file->getKey(), $log->auditable_id);
        $this->assertSame($file->original_name, $log->metadata['original_name']);
    }

    public function test_downloading_and_viewing_a_file_are_audited(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $user = User::factory()->create();
        $this->ensurePermissions(['files.view', 'files.download']);
        $user->givePermissionTo(['files.view', 'files.download']);
        $file = File::create([
            'name' => 'original',
            'original_name' => 'original.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => 'files/'.$user->id.'/original.pdf',
            'file_size' => 11,
            'user_id' => $user->id,
        ]);
        Storage::disk('local')->put($file->storage_path, 'pdf-binary');

        $this->actingAs($user)->get(route('files.download', $file))->assertOk();
        $this->actingAs($user)->get(route('files.blob', $file))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertSame(
            1,
            AuditLog::query()->where('action', 'file.download')->where('auditable_id', $file->id)->count(),
        );
        $this->assertSame(
            1,
            AuditLog::query()->where('action', 'file.view')->where('auditable_id', $file->id)->count(),
        );
    }

    public function test_restoring_a_file_from_trash_is_audited(): void
    {
        $user = User::factory()->create();
        $this->ensurePermissions(['files.delete']);
        $user->givePermissionTo('files.delete');
        $file = File::create([
            'name' => 'reporte',
            'original_name' => 'reporte.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'storage_path' => 'files/'.$user->id.'/reporte.docx',
            'file_size' => 100,
            'user_id' => $user->id,
        ]);
        $file->delete();

        $this->actingAs($user)->post(route('files.restore', $file->id))->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'file.restore',
            'auditable_id' => $file->id,
        ]);
    }

    public function test_document_edits_are_audited(): void
    {
        $user = User::factory()->create();
        $this->ensurePermissions(['docs.edit_realtime']);
        $user->givePermissionTo('docs.edit_realtime');
        $document = Document::create([
            'title' => 'Informe',
            'content' => '<p>v1</p>',
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)->patchJson(route('documents.update', $document), [
            'content' => '<p>v2</p>',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document.update',
            'auditable_id' => $document->id,
        ]);
    }
}
