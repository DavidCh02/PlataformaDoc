<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentImage;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentImageService
{
    private const MAX_IMAGE_BYTES = 5242880; // 5 MB por imagen

    private const MIME_TO_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /**
     * Guarda un binario de imagen como archivo vinculado al documento.
     */
    public function storeImageFile(string $binary, string $extension, Document $document, User $user, string $baseName = 'Imagen'): DocumentImage
    {
        $disk = config('filesystems.default');
        $path = 'files/'.$user->id.'/images/'.Str::uuid().'.'.$extension;
        Storage::disk($disk)->put($path, $binary);

        $mime = array_search($extension, self::MIME_TO_EXT, true) ?: 'application/octet-stream';

        return DocumentImage::create([
            'name' => $baseName,
            'original_name' => $baseName.'.'.$extension,
            'mime_type' => $mime,
            'storage_path' => $path,
            'file_size' => strlen($binary),
            'folder_id' => $document->folder_id,
            'user_id' => $user->id,
            'document_id' => $document->id,
        ]);
    }

    /**
     * Extrae los <img src="data:image/..."> del HTML, los guarda como
     * archivos vinculados y devuelve el HTML con URLs relativas.
     *
     * Red de seguridad al pegar imágenes: sin esto, un UPDATE con megabytes
     * de base64 supera max_allowed_packet y MySQL aborta con
     * "server has gone away" (HTTP 500).
     */
    public function extractEmbeddedImages(string $html, Document $document, User $user): string
    {
        if (! str_contains($html, 'data:image/')) {
            return $html;
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        $changed = false;
        foreach ($dom->getElementsByTagName('img') as $img) {
            $src = $img->getAttribute('src');
            if (! str_starts_with($src, 'data:image/')) {
                continue;
            }
            if (! preg_match('#^data:(image/(?:jpeg|png|webp|gif));base64,(.+)$#s', $src, $matches)) {
                continue;
            }
            $binary = base64_decode($matches[2], true);
            if ($binary === false || $binary === '' || strlen($binary) > self::MAX_IMAGE_BYTES) {
                continue;
            }
            $file = $this->storeImageFile($binary, self::MIME_TO_EXT[$matches[1]], $document, $user, 'Imagen pegada');
            $img->setAttribute('src', route('documents.images.show', [$document->id, $file->id], false));
            $img->removeAttribute('srcset');
            $changed = true;
        }

        if (! $changed) {
            return $html;
        }

        return $dom->saveHTML() ?: $html;
    }

    /**
     * Convierte las URLs relativas de imágenes del documento a base64 en
     * línea. Solo para exportaciones (PDF/DOCX), donde el generador no tiene
     * sesión para descargar las URLs con permiso.
     */
    public function inlineImagesForExport(string $html): string
    {
        if (! str_contains($html, '/images/')) {
            return $html;
        }

        return (string) preg_replace_callback(
            '#src="(/documents/(\d+)/images/(\d+))"#',
            function (array $matches): string {
                $file = DocumentImage::query()
                    ->whereKey($matches[3])
                    ->where('document_id', (int) $matches[2])
                    ->first();

                if (! $file) {
                    return $matches[0];
                }

                $disk = config('filesystems.default');
                if (! Storage::disk($disk)->exists($file->storage_path)) {
                    return $matches[0];
                }

                $binary = (string) Storage::disk($disk)->get($file->storage_path);
                if ($binary === '' || strlen($binary) > 10485760) {
                    return $matches[0];
                }

                return 'src="data:'.$file->mime_type.';base64,'.base64_encode($binary).'"';
            },
            $html,
        );
    }
}
