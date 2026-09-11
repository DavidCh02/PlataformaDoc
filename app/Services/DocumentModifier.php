<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use ZipArchive;

/**
 * Inyecta en un `.docx` la propiedad de metadatos oculta `plataforma_doc_id`
 * (parte `docProps/custom.xml` del paquete OOXML) SIN regenerar el documento.
 *
 * La edición se hace con `ZipArchive` sobre un fichero temporal y solo toca:
 *   - `[Content_Types].xml`            → declara la parte custom-properties.
 *   - `docProps/_rels/custom.xml.rels` → la relaciona con el paquete.
 *   - `docProps/custom.xml`            → añade/actualiza la propiedad.
 *
 * El resto del paquete (document.xml, media, estilos, encabezados y pies,
 * márgenes y recursos WMF/EMF) se conserva byte a byte: Word abre el archivo
 * 100 % fiel y el Add-in lee `plataforma_doc_id` para reconocer la sesión.
 */
class DocumentModifier
{
    /**
     * Nombre de la custom property que el Add-in lee para vincular el
     * documento Word abierto con su registro en la plataforma.
     */
    public const PROPERTY_NAME = 'plataforma_doc_id';

    /**
     * `fmtid` estándar de las custom properties de Office.
     */
    private const FMTID = '{D5CDD505-2E9C-101B-9397-08002B2CF9AE}';

    /**
     * Devuelve una copia del buffer del `.docx` con `plataforma_doc_id`
     * presente (creada o actualizada) apuntando al documento dado.
     */
    public function injectMetadata(string $buffer, int $documentId): string
    {
        abort_unless(class_exists(ZipArchive::class), 500, 'La extensión ZIP no está disponible en el servidor.');

        $tempPath = tempnam(sys_get_temp_dir(), 'pdmod');
        abort_if($tempPath === false, 500, 'No se pudo crear el archivo temporal.');
        file_put_contents($tempPath, $buffer);

        $zip = new ZipArchive;
        $result = $zip->open($tempPath);
        abort_unless($result === true, 422, 'El documento DOCX no es un paquete ZIP válido.');

        try {
            $contentTypes = $zip->getFromName('[Content_Types].xml');
            abort_unless($contentTypes !== false, 422, 'El DOCX no contiene [Content_Types].xml.');

            if (! str_contains($contentTypes, 'custom-properties+xml')) {
                $contentTypes = str_replace(
                    '</Types>',
                    '<Override PartName="/docProps/custom.xml" ContentType="application/vnd.openxmlformats-officedocument.custom-properties+xml"/></Types>',
                    $contentTypes,
                );
                $zip->addFromString('[Content_Types].xml', $contentTypes);
            }

            // Word/PhpWord enlazan la parte desde el PAQUETE (_rels/.rels, tipo
            // custom-properties apuntando a docProps/custom.xml). Sin esa
            // relación de paquete, Word abre el archivo pero NO lo reconoce
            // como custom property, y el Add-in no podría leerla.
            $rootRelsPath = '_rels/.rels';
            $rootRels = $zip->getFromName($rootRelsPath);

            if ($rootRels === false) {
                $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';
            }

            if (! str_contains($rootRels, 'relationships/custom-properties')) {
                $maxId = 0;
                if (preg_match_all('/Id="(rId\d+)"/', $rootRels, $ids)) {
                    foreach ($ids[1] as $rid) {
                        $maxId = max($maxId, (int) substr($rid, 3));
                    }
                }
                $newId = 'rId'.($maxId + 1);
                while (str_contains($rootRels, 'Id="'.$newId.'"')) {
                    $newId = 'rId'.(((int) substr($newId, 3)) + 1);
                }

                $rootRels = str_replace(
                    '</Relationships>',
                    '<Relationship Id="'.$newId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/custom-properties" Target="docProps/custom.xml"/></Relationships>',
                    $rootRels,
                );
                $zip->addFromString($rootRelsPath, $rootRels);
            }

            $customPath = 'docProps/custom.xml';
            $custom = $zip->getFromName($customPath);

            $custom = $custom === false
                ? $this->buildPropertiesXml([2 => $documentId])
                : $this->upsertProperty($custom, $documentId);

            $zip->addFromString($customPath, $custom);
        } finally {
            $zip->close();
        }

        $out = (string) file_get_contents($tempPath);
        @unlink($tempPath);

        Log::info('Metadatos de edición inyectados en el DOCX.', ['document_id' => $documentId]);

        return $out;
    }

    /**
     * Añade o actualiza la propiedad `plataforma_doc_id` en un `custom.xml`
     * existente, reutilizando el `pid` si ya existía y respetando los demás.
     */
    private function upsertProperty(string $custom, int $documentId): string
    {
        $blockPattern = '/<property\b[^>]*name="'.preg_quote(self::PROPERTY_NAME, '/').'"[^>]*>.*?<\/property>/s';

        if (preg_match($blockPattern, $custom, $matches)) {
            $oldBlock = $matches[0];
            $pid = 2;
            if (preg_match('/\bpid="(\d+)"/', $oldBlock, $pidMatch)) {
                $pid = (int) $pidMatch[1];
            }

            return str_replace($oldBlock, $this->propertyXml($pid, $documentId), $custom);
        }

        $maxPid = 1;
        if (preg_match_all('/\bpid="(\d+)"/', $custom, $pids)) {
            $maxPid = max(array_map('intval', $pids[1]));
        }

        $property = $this->propertyXml($maxPid + 1, $documentId);

        if (str_contains($custom, '</Properties>')) {
            return str_replace('</Properties>', $property.'</Properties>', $custom);
        }

        // `custom.xml` autocerrado o vacío (p. ej. el que emite PhpWord):
        // reconstruimos el contenedor `<Properties>` y añadimos la propiedad.
        $opening = '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/custom-properties" '
            .'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">';
        if (preg_match('/<Properties\b[^>]*>/', $custom, $tag)) {
            $opening = preg_replace('#/>$#', '>', $tag[0]);
        }

        return $opening.$property.'</Properties>';
    }

    private function buildPropertiesXml(array $properties): string
    {
        $body = '';
        foreach ($properties as $pid => $value) {
            $body .= $this->propertyXml($pid, $value);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/custom-properties" '
            .'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            .$body
            .'</Properties>';
    }

    private function propertyXml(int $pid, int $value): string
    {
        return '<property fmtid="'.self::FMTID.'" pid="'.$pid.'" name="'.self::PROPERTY_NAME.'">'
            .'<vt:lpwstr>'.$value.'</vt:lpwstr>'
            .'</property>';
    }
}