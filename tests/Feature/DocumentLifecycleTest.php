<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DocumentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function userWhoCanDelete(): User
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'files.delete']);
        $user->givePermissionTo('files.delete');

        return $user;
    }

    public function test_a_document_can_be_sent_to_trash_and_it_is_audited(): void
    {
        $user = $this->userWhoCanDelete();
        $document = Document::create([
            'title' => 'Documento creado',
            'content' => '<p>contenido</p>',
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)->delete(route('documents.destroy', $document))->assertRedirect();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document.delete',
            'auditable_id' => $document->id,
        ]);
    }

    public function test_a_deleted_document_can_be_restored_and_it_is_audited(): void
    {
        $user = $this->userWhoCanDelete();
        $document = Document::create([
            'title' => 'Documento restaurable',
            'content' => '<p>contenido</p>',
            'user_id' => $user->id,
        ]);
        $document->delete();

        $this->actingAs($user)->post(route('documents.restore', $document->id))->assertRedirect();

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document.restore',
            'auditable_id' => $document->id,
        ]);
    }

    public function test_a_document_can_be_deleted_permanently_and_it_is_audited(): void
    {
        $user = $this->userWhoCanDelete();
        $document = Document::create([
            'title' => 'Documento a borrar',
            'content' => '<p>contenido</p>',
            'user_id' => $user->id,
        ]);
        $document->delete();

        $this->actingAs($user)->delete(route('documents.force-destroy', $document->id))->assertRedirect();

        $this->assertNull(Document::withTrashed()->find($document->id));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document.force_delete',
            'auditable_id' => $document->id,
        ]);
    }

    public function test_users_without_delete_permission_are_forbidden(): void
    {
        $owner = User::factory()->create();
        $document = Document::create([
            'title' => 'Privado',
            'content' => '<p>contenido</p>',
            'user_id' => $owner->id,
        ]);

        $this->actingAs($owner)->delete(route('documents.destroy', $document))->assertForbidden();
        $this->assertNotSoftDeleted('documents', ['id' => $document->id]);
    }
}
