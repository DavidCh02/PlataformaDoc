<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Spatie\Browsershot\Browsershot;
use Throwable;

class DocumentPdfExporter
{
    public function export(Document $document): string
    {
        $html = view('documents.pdf', [
            'title' => $document->title,
            'content' => $document->content ?? '',
        ])->render();

        $directory = storage_path('app/temp/pdf');
        File::ensureDirectoryExists($directory);

        $path = $directory.DIRECTORY_SEPARATOR.'document-'.$document->id.'-'.time().'.pdf';

        try {
            $browsershot = Browsershot::html($html)
                ->format('A4')
                ->margins(22, 20, 22, 20, 'mm')
                ->showBackground()
                ->waitUntilNetworkIdle();

            if ($nodeBinary = config('services.browsershot.node_binary')) {
                $browsershot->setNodeBinary($nodeBinary);
            }

            if ($npmBinary = config('services.browsershot.npm_binary')) {
                $browsershot->setNpmBinary($npmBinary);
            }

            // En Windows no se usa NODE_PATH: Node resuelve require('puppeteer')
            // subiendo directorios desde vendor/spatie/browsershot/bin hasta el
            // node_modules del proyecto. Declararlo ayuda en Linux/macOS.
            $browsershot->setNodeModulePath(base_path('node_modules'));

            // Chrome/Edge del sistema: evita descargar Chromium al instalar.
            if ($chromePath = config('services.browsershot.chrome_path') ?? static::detectChromeBinary()) {
                $browsershot->setChromePath($chromePath);
            }

            $browsershot->save($path);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'No se pudo generar el PDF. Verifica que Node.js y Puppeteer estén instalados.',
                previous: $exception,
            );
        }

        return $path;
    }

    /**
     * Localiza un binario Chromium/Chrome/Edge instalado en el sistema para
     * no depender de la descarga de Chromium que hace Puppeteer.
     */
    public static function detectChromeBinary(): ?string
    {
        $candidates = [
            'C:\Program Files\Google\Chrome\Application\chrome.exe',
            'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
            'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
            'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium-browser',
            '/usr/bin/chromium',
            '/snap/bin/chromium',
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
