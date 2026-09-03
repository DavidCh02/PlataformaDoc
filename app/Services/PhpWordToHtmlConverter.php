<?php

namespace App\Services;

use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\PageBreak;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style\Cell as CellStyle;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Style\Paragraph;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Convierte el modelo de PHPWord a HTML fiel para el editor TipTap.
 *
 * A diferencia del writer HTML de PHPWord, este conversor conserva las
 * tabulaciones, los espacios consecutivos, los saltos de línea dentro de un
 * párrafo, la alineación, la sangría, el interlineado y los estilos de
 * fuente/párrafo con CSS inline, e incrusta las imágenes como data URIs.
 */
class PhpWordToHtmlConverter
{
    private PhpWord $phpWord;

    private string $sourcePath;

    private ?ZipArchive $zip = null;

    public function __construct(PhpWord $phpWord, string $sourcePath)
    {
        $this->phpWord = $phpWord;
        $this->sourcePath = $sourcePath;
    }

    public function convert(): string
    {
        $this->openZip();

        $html = '';
        foreach ($this->phpWord->getSections() as $section) {
            $html .= $this->writeContainer($section->getElements());
        }

        $this->closeZip();

        $html = trim($html);
        $background = $this->pageBackgroundColor();

        if ($background !== null) {
            return "<!-- word-page-background:#{$background} -->\n{$html}";
        }

        return $html;
    }

    private function openZip(): void
    {
        if (is_file($this->sourcePath)) {
            $zip = new ZipArchive();
            if ($zip->open($this->sourcePath) === true) {
                $this->zip = $zip;
            }
        }
    }

    private function closeZip(): void
    {
        if ($this->zip !== null) {
            $this->zip->close();
            $this->zip = null;
        }
    }

    /**
     * Lee el color de fondo de página (w:background) del propio DOCX.
     */
    private function pageBackgroundColor(): ?string
    {
        if ($this->zip === null) {
            return null;
        }

        $documentXml = $this->zip->getFromName('word/document.xml');
        if ($documentXml === false) {
            return null;
        }

        if (preg_match('/<w:background[^>]*w:color="([0-9A-Fa-f]{6})"/', $documentXml, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }

    private function writeContainer(array $elements): string
    {
        $html = '';
        $pendingList = [];

        $flushList = function () use (&$html, &$pendingList): void {
            if ($pendingList !== []) {
                $html .= $this->writeListGroup($pendingList);
                $pendingList = [];
            }
        };

        foreach ($elements as $element) {
            if ($element instanceof ListItem) {
                $pendingList[] = $element;

                continue;
            }

            $flushList();
            $html .= $this->writeElement($element);
        }

        $flushList();

        return $html;
    }

    private function writeElement(mixed $element): string
    {
        if ($element instanceof TextRun) {
            return $this->writeTextRun($element);
        }

        if ($element instanceof Table) {
            return $this->writeTable($element);
        }

        if ($element instanceof Title) {
            return $this->writeTitle($element);
        }

        if ($element instanceof Image) {
            return $this->writeImageElement($element);
        }

        if ($element instanceof Link) {
            return $this->writeLink($element);
        }

        if ($element instanceof PageBreak) {
            return '<p class="word-page-break"></p>';
        }

        if ($element instanceof TextBreak) {
            return '<br>';
        }

        if (method_exists($element, 'getElements')) {
            return $this->writeContainer($element->getElements());
        }

        if (method_exists($element, 'getText')) {
            return $this->writeText($element);
        }

        return '';
    }

    private function writeTextRun(TextRun $run): string
    {
        $content = '';
        foreach ($run->getElements() as $element) {
            $content .= $this->writeRunElement($element);
        }

        return $this->wrapParagraphOrHeading($content, $run->getParagraphStyle());
    }

    private function writeRunElement(mixed $element): string
    {
        if ($element instanceof TextBreak) {
            return '<br>';
        }

        if ($element instanceof TextRun) {
            $content = '';
            foreach ($element->getElements() as $nested) {
                $content .= $this->writeRunElement($nested);
            }

            return $content;
        }

        if ($element instanceof Image) {
            return $this->writeImageElement($element);
        }

        if ($element instanceof Link) {
            return $this->writeInlineLink($element);
        }

        if (method_exists($element, 'getText')) {
            return $this->wrapFont($element);
        }

        return '';
    }

    private function writeText(mixed $text): string
    {
        $paragraphStyle = method_exists($text, 'getParagraphStyle')
            ? $text->getParagraphStyle()
            : null;

        return $this->wrapParagraphOrHeading($this->wrapFont($text), $paragraphStyle);
    }
private function writeTitle(Title $title): string
    {
        $depth = (int) $title->getDepth();
        $tag = $depth >= 1 && $depth <= 6 ? 'h'.$depth : 'h1';

        $text = $title->getText();

        // El titulo puede ser texto plano o un TextRun con varios elementos.
        if ($text instanceof TextRun) {
            $content = '';
            foreach ($text->getElements() as $element) {
                $content .= $this->writeRunElement($element);
            }
        } else {
            $content = $this->escapeText((string) $text);
        }

        return '<'.$tag.'>'.$content.'</'.$tag.'>';
    }

    private function writeLink(Link $link): string
    {
        $paragraphStyle = method_exists($link, 'getParagraphStyle')
            ? $link->getParagraphStyle()
            : null;

        return $this->wrapParagraph($this->writeInlineLink($link), $paragraphStyle);
    }

    private function writeInlineLink(Link $link): string
    {
        $text = $link->getText() ?? $link->getSource();
        $href = htmlspecialchars($link->getSource(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $inner = $this->escapeText($text);

        $fontStyle = $link->getFontStyle();
        if ($fontStyle instanceof Font) {
            $css = $this->fontCss($fontStyle);
            if ($css !== '') {
                $inner = '<span style="'.$css.'">'.$inner.'</span>';
            }
        }

        return '<a href="'.$href.'" style="color:#1a56db;text-decoration:underline">'.$inner.'</a>';
    }

    private function writeListGroup(array $items): string
    {
        $html = '<ul style="margin:0.35em 0 0.35em 1.1em;padding-left:1.5em">';

        foreach ($items as $item) {
            $inner = $this->escapeText($item->getText());
            $textObject = method_exists($item, 'getTextObject') ? $item->getTextObject() : null;

            if ($textObject !== null
                && method_exists($textObject, 'getFontStyle')
                && $textObject->getFontStyle() instanceof Font) {
                $css = $this->fontCss($textObject->getFontStyle());
                if ($css !== '') {
                    $inner = '<span style="'.$css.'">'.$inner.'</span>';
                }
            }

            $html .= '<li>'.$inner.'</li>';
        }

        return $html.'</ul>';
    }

    private function writeImageElement(Image $image): string
    {
        try {
            $data = $image->getImageStringData(true);
        } catch (Throwable) {
            return '';
        }

        if ($data === null || $data === '') {
            return '';
        }

        $mime = $image->getImageType() ?: 'image/png';
        $base64 = preg_replace('/\s+/', '', $data) ?? '';

        $attrs = '';
        $alignStyle = '';
        $style = $image->getStyle();

        if ($style !== null && method_exists($style, 'getWidth') && method_exists($style, 'getHeight')) {
            $width = $style->getWidth();
            $height = $style->getHeight();
            if ($width !== null && $height !== null) {
                $attrs .= ' width="'.round((float) $width).'"';
                $attrs .= ' height="'.round((float) $height).'"';
            }
        }

        if ($style !== null && method_exists($style, 'getAlignment')) {
            $alignment = $style->getAlignment();
            $alignmentMap = ['center' => 'center', 'right' => 'right', 'both' => 'justify'];
            if (isset($alignmentMap[$alignment])) {
                $alignStyle = ' style="text-align:'.$alignmentMap[$alignment].'"';
            }
        }

        $alt = $image->getName()
            ? htmlspecialchars($image->getName(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : 'Imagen';

        return '<p'.$alignStyle.'><img src="data:'.$mime.';base64,'.$base64.'"'.$attrs.' alt="'.$alt.'"></p>';
    }
private function writeTable(Table $table): string
    {
        $html = '<table style="width:100%;border-collapse:collapse;margin:0.35em 0">';

        foreach ($table->getRows() as $row) {
            if (! method_exists($row, 'getCells')) {
                continue;
            }

            $html .= '<tr>';
            foreach ($row->getCells() as $cell) {
                $css = ['border:1px solid #94a3b8', 'padding:4px 6px', 'vertical-align:top'];

                $cellStyle = method_exists($cell, 'getStyle') ? $cell->getStyle() : null;
                if ($cellStyle instanceof CellStyle) {
                    $background = method_exists($cellStyle, 'getBgColor') ? $cellStyle->getBgColor() : null;
                    if ($background) {
                        $css[] = 'background-color:#'.ltrim($background, '#');
                    }
                }

                $colspan = 1;
                if (method_exists($cell, 'getGridSpan')) {
                    $gridSpan = (int) $cell->getGridSpan();
                    if ($gridSpan > 1) {
                        $colspan = $gridSpan;
                    }
                }

                $html .= '<td style="'.implode(';', $css).'"'.($colspan > 1 ? ' colspan="'.$colspan.'"' : '').'>';
                if (method_exists($cell, 'getElements')) {
                    $html .= $this->writeContainer($cell->getElements());
                }
                $html .= '</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</table>';
    }

    private function wrapParagraph(string $content, mixed $paragraphStyle): string
    {
        $css = $paragraphStyle instanceof Paragraph ? $this->paragraphCss($paragraphStyle) : '';

        return '<p'.($css !== '' ? ' style="'.$css.'"' : '').'>'.$content.'</p>';
    }

    /**
     * Igual que wrapParagraph, pero si el estilo de párrafo es un nombre de
     * estilo de encabezado ("Heading 2", "Title"...), emite un <hN>.
     */
    private function wrapParagraphOrHeading(string $content, mixed $paragraphStyle): string
    {
        if (is_string($paragraphStyle)) {
            if (preg_match('/^Heading\s*([1-6])$/i', $paragraphStyle, $matches) === 1) {
                $tag = 'h'.$matches[1];

                return '<'.$tag.'>'.$content.'</'.$tag.'>';
            }
            if (preg_match('/^Title$/i', $paragraphStyle) === 1) {
                return '<h1>'.$content.'</h1>';
            }
        }

        return $this->wrapParagraph($content, $paragraphStyle);
    }

    private function wrapFont(mixed $element): string
    {
        if (! method_exists($element, 'getText')) {
            return '';
        }

        $content = $this->escapeText($element->getText());

        if (! method_exists($element, 'getFontStyle')) {
            return $content;
        }

        $fontStyle = $element->getFontStyle();
        if ($fontStyle instanceof Font) {
            $css = $this->fontCss($fontStyle);

            return $css !== '' ? '<span style="'.$css.'">'.$content.'</span>' : $content;
        }

        return $content;
    }

    private function escapeText(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Saltos de línea dentro de un párrafo → <br> (nodo hardBreak).
        $text = preg_replace('/\r?\n/', '<br>', $text) ?? $text;

        // Tabulaciones → span con ancho para conservarlas visualmente.
        $text = preg_replace_callback(
            '/\t+/',
            static fn (array $matches): string => '<span class="word-tab" style="min-width:2.5em"></span>',
            $text,
        ) ?? $text;

        // Espacios múltiples consecutivos → &nbsp; para que no colapsen.
        $text = preg_replace_callback(
            '/ {2,}/',
            static fn (array $matches): string => str_replace(' ', '&nbsp;', $matches[0]),
            $text,
        ) ?? $text;

        return $text;
    }
private function fontCss(Font $font): string
    {
        $css = [];

        $name = trim((string) $font->getName());
        if ($name !== '' && $name !== 'undefined') {
            $css[] = "font-family:'".addslashes($name)."'";
        }

        $size = $font->getSize();
        if ($size !== null && (float) $size > 0) {
            $css[] = 'font-size:'.round((float) $size, 2).'pt';
        }

        if ($font->isBold()) {
            $css[] = 'font-weight:bold';
        }

        if ($font->isItalic()) {
            $css[] = 'font-style:italic';
        }

        $color = $font->getColor();
        if ($color) {
            $css[] = 'color:#'.ltrim($color, '#');
        }

        $shading = $font->getShading();
        if ($shading !== null && method_exists($shading, 'getFill')) {
            $fill = $shading->getFill();
            if ($fill) {
                $css[] = 'background-color:#'.ltrim($fill, '#');
            }
        }

        $decorations = [];
        $underline = $font->getUnderline();
        if ($underline !== null && $underline !== 'none') {
            $decorations[] = 'underline';
        }
        if ($font->isStrikethrough() || $font->isDoubleStrikethrough()) {
            $decorations[] = 'line-through';
        }
        if ($decorations !== []) {
            $css[] = 'text-decoration:'.implode(' ', $decorations);
        }

        if ($font->isSuperScript()) {
            $css[] = 'vertical-align:super';
            $css[] = 'font-size:smaller';
        }

        if ($font->isSubScript()) {
            $css[] = 'vertical-align:sub';
            $css[] = 'font-size:smaller';
        }

        if ($font->isAllCaps()) {
            $css[] = 'text-transform:uppercase';
        }

        if ($font->isSmallCaps()) {
            $css[] = 'font-variant:small-caps';
        }

        $letterSpacing = $font->getSpacing();
        if ($letterSpacing !== null && (int) $letterSpacing !== 0) {
            $css[] = 'letter-spacing:'.round((int) $letterSpacing / 20, 2).'pt';
        }

        return implode(';', $css);
    }

    private function paragraphCss(Paragraph $style): string
    {
        $css = [];

        $alignmentMap = ['center' => 'center', 'right' => 'right', 'both' => 'justify', 'justify' => 'justify'];
        $alignment = $style->getAlignment();
        if ($alignment !== '' && $alignment !== null && isset($alignmentMap[$alignment])) {
            $css[] = 'text-align:'.$alignmentMap[$alignment];
        }

        $indentation = $style->getIndentation();
        if ($indentation !== null) {
            if ($indentation->getLeft() > 0) {
                $css[] = 'padding-left:'.$this->indentToPt($indentation->getLeft()).'pt';
            }
            if ($indentation->getRight() > 0) {
                $css[] = 'padding-right:'.$this->indentToPt($indentation->getRight()).'pt';
            }
            if ($indentation->getFirstLine() > 0) {
                $css[] = 'text-indent:'.$this->indentToPt($indentation->getFirstLine()).'pt';
            }
            if ($indentation->getHanging() > 0) {
                $css[] = 'text-indent:-'.$this->indentToPt($indentation->getHanging()).'pt';
            }
        }

        $before = $style->getSpaceBefore();
        if ($before > 0) {
            $css[] = 'margin-top:'.$this->twipsToPt($before).'pt';
        }

        $after = $style->getSpaceAfter();
        if ($after > 0) {
            $css[] = 'margin-bottom:'.$this->twipsToPt($after).'pt';
        }

        $css = array_merge($css, $this->lineHeightCss($style));

        $shading = $style->getShading();
        if ($shading !== null && method_exists($shading, 'getFill')) {
            $fill = $shading->getFill();
            if ($fill) {
                $css[] = 'background-color:#'.ltrim($fill, '#');
            }
        }

        return implode(';', $css);
    }

    private function lineHeightCss(Paragraph $style): array
    {
        $lineHeight = $style->getLineHeight();
        if ($lineHeight !== null && (int) $lineHeight > 0) {
            $value = (float) $lineHeight;
            // PHPWord puede guardar la altura de línea en "twips de línea" (240).
            if ($value > 10) {
                return ['line-height:'.round($value / 240, 2)];
            }

            return ['line-height:'.round($value, 2)];
        }

        $line = $style->getSpacing();
        $rule = $style->getSpacingLineRule();
        if ($line !== null && $rule !== null && in_array($rule, ['exact', 'atLeast'], true)) {
            return ['line-height:'.$this->twipsToPt($line).'pt'];
        }

        return [];
    }

    private function twipsToPt(float|int $twips): float|int
    {
        $pt = round($twips / 20, 2);

        return $pt == (int) $pt ? (int) $pt : $pt;
    }

    /**
     * Convierte una sangría a puntos. En esta versión de PHPWord el lector
     * multiplica los valores de sangría por 720 (y el writer de PHPWord lo
     * hace una vez más al guardar), así que se normaliza dividiendo por 720
     * hasta que el valor sea una cantidad de twips plausible.
     */
    private function indentToPt(float|int $value): float|int
    {
        $value = (float) $value;
        while ($value > 200000) {
            $value /= 720;
        }

        return $this->twipsToPt($value);
    }
}