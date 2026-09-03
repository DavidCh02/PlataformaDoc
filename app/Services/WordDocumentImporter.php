<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory;
use RuntimeException;
use ZipArchive;

class WordDocumentImporter
{
    public function toHtml(string $sourcePath, string $extension): string
    {
        if (strtolower($extension) === 'doc') {
            throw new RuntimeException('La edición de archivos .doc requiere convertirlos previamente a .docx.');
        }

        try {
            $sanitizedPath = $this->sanitizeUnsupportedImages($sourcePath);
            $phpWord = IOFactory::load($sanitizedPath);
            $converter = new PhpWordToHtmlConverter($phpWord, $sanitizedPath);
            $html = $converter->convert();
            if ($sanitizedPath !== $sourcePath) {
                @unlink($sanitizedPath);
            }

            return $html;
        } catch (\Throwable $exception) {
            if (isset($sanitizedPath) && $sanitizedPath !== $sourcePath) {
                @unlink($sanitizedPath);
            }

            throw new RuntimeException(
                'No se pudo preparar una versión editable. Usa la vista previa para conservar el diseño original.',
                previous: $exception,
            );
        }
    }

    private function sanitizeUnsupportedImages(string $sourcePath): string
    {
        $zip = new ZipArchive();
        if ($zip->open($sourcePath) !== true) {
            throw new RuntimeException('No se pudo leer el archivo DOCX.');
        }

        $unsupportedRelationships = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (! is_string($name) || ! str_ends_with($name, '.rels')) {
                continue;
            }

            $relationships = simplexml_load_string($zip->getFromIndex($index));
            if ($relationships === false) {
                continue;
            }

            foreach ($relationships->Relationship as $relationship) {
                if (preg_match('/\.(wmf|emf|svg)$/i', (string) $relationship['Target'])) {
                    $unsupportedRelationships[(string) $relationship['Id']] = true;
                }
            }
        }

        if ($unsupportedRelationships === []) {
            $zip->close();
            return $sourcePath;
        }

        $sanitizedPath = tempnam(sys_get_temp_dir(), 'docx_edit_').'.docx';
        copy($sourcePath, $sanitizedPath);
        $zip->close();

        $sanitizedZip = new ZipArchive();
        if ($sanitizedZip->open($sanitizedPath) !== true) {
            throw new RuntimeException('No se pudo preparar el DOCX para editarlo.');
        }

        for ($index = 0; $index < $sanitizedZip->numFiles; $index++) {
            $name = $sanitizedZip->getNameIndex($index);
            if (! is_string($name) || ! str_ends_with($name, '.xml') || ! str_contains($name, 'word/')) {
                continue;
            }

            $xml = $sanitizedZip->getFromIndex($index);
            if (! is_string($xml) || (! str_contains($xml, 'drawing') && ! str_contains($xml, 'pict'))) {
                continue;
            }

            $dom = new \DOMDocument('1.0', 'UTF-8');
            libxml_use_internal_errors(true);
            if (! $dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING)) {
                libxml_clear_errors();
                continue;
            }
            libxml_clear_errors();

            $xpath = new \DOMXPath($dom);
            $nodes = $xpath->query('//*[local-name()="drawing" or local-name()="pict"]');
            if ($nodes === false) {
                continue;
            }

            foreach (iterator_to_array($nodes) as $node) {
                $references = $xpath->query('.//@*[local-name()="embed" or local-name()="id"]', $node);
                $remove = false;
                if ($references !== false) {
                    foreach ($references as $reference) {
                        if (isset($unsupportedRelationships[$reference->nodeValue])) {
                            $remove = true;
                            break;
                        }
                    }
                }

                if ($remove && $node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }

            $sanitizedZip->addFromString($name, $dom->saveXML());
        }

        $sanitizedZip->close();

        return $sanitizedPath;
    }
}
