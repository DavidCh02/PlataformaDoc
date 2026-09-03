<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PdfExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_document_can_be_exported_as_a_downloadable_pdf(): void
    {
        Permission::firstOrCreate(['name' => 'files.view']);
        $user = User::factory()->create();
        $user->givePermissionTo('files.view');
        $document = Document::create([
            'title' => 'Informe exportable',
            'content' => '<h1>Informe</h1><p style="text-align:justify">Contenido de prueba con tabs y espacios.</p>',
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('documents.export-pdf', $document));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertGreaterThan(0, $response->getFile()->getSize());
        $this->assertStringStartsWith('%PDF', (string) file_get_contents($response->getFile()->getRealPath()));
    }

    public function test_users_without_view_permission_cannot_export(): void
    {
        $user = User::factory()->create();
        $document = Document::create([
            'title' => 'Privado',
            'content' => '<p>secreto</p>',
            'user_id' => User::factory()->create()->id,
        ]);

        $this->actingAs($user)->get(route('documents.export-pdf', $document))->assertForbidden();
    }
}
