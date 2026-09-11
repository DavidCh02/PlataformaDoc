<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import { Extension } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import TextAlign from '@tiptap/extension-text-align';
import TextStyle from '@tiptap/extension-text-style';
import Color from '@tiptap/extension-color';
import Highlight from '@tiptap/extension-highlight';
import FontFamily from '@tiptap/extension-font-family';
import Link from '@tiptap/extension-link';
import Image from '@tiptap/extension-image';
import Table from '@tiptap/extension-table';
import TableRow from '@tiptap/extension-table-row';
import TableCell from '@tiptap/extension-table-cell';
import TableHeader from '@tiptap/extension-table-header';
import TaskList from '@tiptap/extension-task-list';
import TaskItem from '@tiptap/extension-task-item';
import Placeholder from '@tiptap/extension-placeholder';
import Collaboration from '@tiptap/extension-collaboration';
import * as Y from 'yjs';
import {
    AlignCenter, AlignJustify, AlignLeft, AlignRight, ArrowDown, ArrowLeft,
    ArrowRight, ArrowUp, Bold, ChevronDown, Code, Code2, Eraser,
    FileImage, Heading1, Heading2, Heading3, Highlighter, ImageMinus, ImagePlus,
    IndentDecrease, IndentIncrease, Italic, Link as LinkIcon, List, ListChecks,
    ListOrdered, Minus, Palette, Quote, Redo2, Strikethrough,
    Subscript, Superscript, Table2, TableCellsMerge, TableCellsSplit, TableProperties,
    TextWrap, Trash2, Type, Underline as UnderlineIcon,
    Undo2, X,
} from 'lucide-vue-next';

const props = defineProps({
    content: { type: [String, Object], default: '<p></p>' },
    editable: { type: Boolean, default: true },
    placeholder: { type: String, default: 'Empieza a escribir...' },
    collaborationDocument: { type: Object, default: null },
    imageUpload: { type: Function, default: null },
    exportPdfUrl: { type: String, default: '' },
    exportDocxUrl: { type: String, default: '' },
});

const emit = defineEmits(['update:content', 'update', 'save-request', 'collaboration-update', 'export-pdf', 'export-docx']);
const selectedColor = ref('#1f2937');
const selectedHighlight = ref('#fef08a');
const selectedFont = ref('Arial');
const selectedFontSize = ref('12pt');
const imageInput = ref(null);
const ydoc = props.collaborationDocument || new Y.Doc();
const ownsYdoc = !props.collaborationDocument;

const FontSize = Extension.create({
    name: 'fontSize',
    addGlobalAttributes() {
        return [{ types: ['textStyle'], attributes: {
            fontSize: {
                default: null,
                parseHTML: element => element.style.fontSize || null,
                renderHTML: attributes => attributes.fontSize ? { style: `font-size: ${attributes.fontSize}` } : {},
            },
        } }];
    },
});

const ParagraphIndent = Extension.create({
    name: 'paragraphIndent',
    addGlobalAttributes() {
        return [{ types: ['paragraph', 'heading'], attributes: {
            textIndent: {
                default: null,
                parseHTML: element => element.style.textIndent || null,
                renderHTML: attributes => attributes.textIndent ? { style: `text-indent: ${attributes.textIndent}` } : {},
            },
        } }];
    },
    addCommands() {
        return {
            setTextIndent: textIndent => ({ commands }) => commands.updateAttributes('paragraph', { textIndent }),
        };
    },
});

const ParagraphSpacing = Extension.create({
    name: 'paragraphSpacing',
    addGlobalAttributes() {
        return [{ types: ['paragraph', 'heading'], attributes: {
            lineHeight: {
                default: null,
                parseHTML: element => element.style.lineHeight || null,
                renderHTML: attributes => attributes.lineHeight ? { style: `line-height: ${attributes.lineHeight}` } : {},
            },
            marginTop: {
                default: null,
                parseHTML: element => element.style.marginTop || null,
                renderHTML: attributes => attributes.marginTop ? { style: `margin-top: ${attributes.marginTop}` } : {},
            },
            marginBottom: {
                default: null,
                parseHTML: element => element.style.marginBottom || null,
                renderHTML: attributes => attributes.marginBottom ? { style: `margin-bottom: ${attributes.marginBottom}` } : {},
            },
            paddingLeft: {
                default: null,
                parseHTML: element => element.style.paddingLeft || null,
                renderHTML: attributes => attributes.paddingLeft ? { style: `padding-left: ${attributes.paddingLeft}` } : {},
            },
            paddingRight: {
                default: null,
                parseHTML: element => element.style.paddingRight || null,
                renderHTML: attributes => attributes.paddingRight ? { style: `padding-right: ${attributes.paddingRight}` } : {},
            },
        } }];
    },
});

const TextEffects = Extension.create({
    name: 'textEffects',
    addGlobalAttributes() {
        return [{ types: ['textStyle'], attributes: {
            letterSpacing: {
                default: null,
                parseHTML: element => element.style.letterSpacing || null,
                renderHTML: attributes => attributes.letterSpacing ? { style: `letter-spacing: ${attributes.letterSpacing}` } : {},
            },
            verticalAlign: {
                default: null,
                parseHTML: element => element.style.verticalAlign || null,
                renderHTML: attributes => attributes.verticalAlign ? { style: `vertical-align: ${attributes.verticalAlign}` } : {},
            },
            textTransform: {
                default: null,
                parseHTML: element => element.style.textTransform || null,
                renderHTML: attributes => attributes.textTransform ? { style: `text-transform: ${attributes.textTransform}` } : {},
            },
        } }];
    },
});

const TableCellBackground = Extension.create({
    name: 'tableCellBackground',
    addGlobalAttributes() {
        return [{ types: ['tableCell', 'tableHeader'], attributes: {
            cellBackgroundColor: {
                default: null,
                parseHTML: element => element.style.backgroundColor || null,
                renderHTML: attributes => attributes.cellBackgroundColor ? { style: `background-color: ${attributes.cellBackgroundColor}` } : {},
            },
        } }];
    },
});

const ImageStyle = Extension.create({
    name: 'imageStyle',
    addGlobalAttributes() {
        return [{ types: ['image'], attributes: {
            imgWidth: {
                default: null,
                parseHTML: element => element.style.width || null,
                renderHTML: attributes => attributes.imgWidth ? { style: `width: ${attributes.imgWidth}` } : {},
            },
            imgAlign: {
                default: null,
                parseHTML: element => {
                    const float = element.style.cssFloat || '';
                    if (float === 'left') return 'left';
                    if (float === 'right') return 'right';
                    if ((element.style.display || '') === 'block'
                        && (element.style.marginLeft === 'auto' || element.style.marginRight === 'auto')) return 'center';
                    return null;
                },
                renderHTML: attributes => {
                    if (attributes.imgAlign === 'left') return { style: 'float: left; margin: 0 1rem 0.5rem 0;' };
                    if (attributes.imgAlign === 'right') return { style: 'float: right; margin: 0 0 0.5rem 1rem;' };
                    if (attributes.imgAlign === 'center') return { style: 'display: block; margin-left: auto; margin-right: auto;' };
                    return {};
                },
            },
        } }];
    },
    addCommands() {
        return {
            setImageWidth: width => ({ commands }) => commands.updateAttributes('image', { imgWidth: width || null }),
            setImageAlign: align => ({ commands }) => commands.updateAttributes('image', { imgAlign: align === 'inline' ? null : align }),
        };
    },
});

const KeyboardShortcuts = Extension.create({
    name: 'keyboardShortcuts',
    addKeyboardShortcuts() {
        return {
            'Mod-u': () => this.editor.commands.toggleUnderline(),
            'Shift-Mod-x': () => this.editor.commands.toggleStrike(),
            'Mod-e': () => this.editor.commands.setTextAlign('center'),
            'Mod-j': () => this.editor.commands.setTextAlign('justify'),
            'Mod-r': () => this.editor.commands.setTextAlign('right'),
            'Mod-y': () => this.editor.commands.redo(),
        };
    },
});

const TabKey = Extension.create({
    name: 'tabKey',
    addKeyboardShortcuts() {
        return {
            Tab: () => {
                const { editor } = this;
                if (editor.isActive('table')) return false;
                if (editor.isActive('bulletList') || editor.isActive('orderedList')) {
                    if (editor.commands.sinkListItem('listItem')) return true;
                }
                if (editor.isActive('taskList')) {
                    if (editor.commands.sinkListItem('taskItem')) return true;
                }
                return editor.commands.insertContent('\t');
            },
            'Shift-Tab': () => {
                const { editor } = this;
                if (editor.isActive('bulletList') || editor.isActive('orderedList')) {
                    if (editor.commands.liftListItem('listItem')) return true;
                }
                if (editor.isActive('taskList')) {
                    if (editor.commands.liftListItem('taskItem')) return true;
                }
                return editor.chain().focus().setTextIndent('0').run();
            },
        };
    },
});

const editor = useEditor({
    editable: props.editable,
    extensions: [
        // Collaboration trae su propio historial: desactivar el de
        // StarterKit evita el aviso de incompatibilidad con History.
        StarterKit.configure({ history: false }),
        Underline,
        TextStyle,
        Color,
        Highlight.configure({ multicolor: true }),
        FontFamily,
        FontSize,
        ParagraphIndent,
        ParagraphSpacing,
        TextEffects,
        TableCellBackground,
        TextAlign.configure({ types: ['heading', 'paragraph'] }),
        Link.configure({ openOnClick: false, autolink: true }),
        Image.configure({ allowBase64: true }),
        ImageStyle,
        Table.configure({ resizable: true }),
        TableRow,
        TableHeader,
        TableCell,
        TaskList,
        TaskItem.configure({ nested: true }),
        Placeholder.configure({ placeholder: props.placeholder }),
        Collaboration.configure({ document: ydoc }),
        KeyboardShortcuts,
        TabKey,
    ],
    onUpdate: ({ editor: currentEditor }) => {
        const html = currentEditor.getHTML();
        emit('update:content', html);
        emit('collaboration-update', html);
        emit('update', { html, json: currentEditor.getJSON(), editor: currentEditor });
        updatePageEstimate();
    },
    onCreate: ({ editor: createdEditor }) => {
        if (ydoc.getXmlFragment('default').length === 0 && props.content) {
            createdEditor.commands.setContent(props.content, false, { preserveWhitespace: 'full' });
        }
    },
});

watch(() => props.content, value => {
    if (!editor.value || value === editor.value.getHTML()) return;
    // Cambio remoto: conservar la posición del cursor al aplicar el contenido.
    const { from, to } = editor.value.state.selection;
    editor.value.commands.setContent(value || '<p></p>', false, { preserveWhitespace: 'full' });
    try {
        const size = editor.value.state.doc.content.size;
        editor.value.commands.setTextSelection({
            from: Math.max(0, Math.min(from, size)),
            to: Math.max(0, Math.min(to, size)),
        });
    } catch {
        // Posiciones inválidas tras el cambio: se deja el cursor por defecto.
    }
});

const wordCount = computed(() => {
    const text = editor.value?.getText({ blockSeparator: ' ' }).trim() || '';
    return text ? text.split(/\s+/).length : 0;
});

const run = (command) => {
    if (props.editable) command?.focus().run();
};

const setHeading = (level) => {
    if (!editor.value) return;
    const chain = editor.value.chain().focus();
    level === 0 ? chain.setParagraph().run() : chain.toggleHeading({ level }).run();
};

const setIndent = (value) => editor.value?.chain().focus().setTextIndent(value).run();
const toggleVerticalAlign = (value) => {
    if (!editor.value) return;
    const current = editor.value.getAttributes('textStyle').verticalAlign;
    if (current === value) {
        run(editor.value.chain().unsetMark('textStyle'));
    } else {
        run(editor.value.chain().setMark('textStyle', { verticalAlign: value }));
    }
};
const verticalAlignActive = value => editor.value?.getAttributes('textStyle').verticalAlign === value;
const addLink = () => {
    const url = window.prompt('URL del enlace');
    if (!editor.value) return;
    url ? run(editor.value.chain().setLink({ href: url })) : run(editor.value.chain().unsetLink());
};

const addImage = async (file) => {
    if (!file || !editor.value) return;
    let src = '';
    if (props.imageUpload) src = await props.imageUpload(file);
    else src = await new Promise(resolve => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.readAsDataURL(file);
    });
    if (src) editor.value.chain().focus().setImage({ src, alt: file.name }).run();
};

const selectImage = event => addImage(event.target.files?.[0]);
const addImageFromUrl = () => {
    const url = window.prompt('URL de la imagen');
    if (url) editor.value?.chain().focus().setImage({ src: url, alt: 'Imagen del documento' }).run();
};
const insertTable = () => editor.value?.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run();
const clearFormatting = () => editor.value?.chain().focus().unsetAllMarks().clearNodes().run();
const headingActive = level => level === 0 ? editor.value?.isActive('paragraph') : editor.value?.isActive('heading', { level });

// Imagen seleccionada: tamaño y ajuste de texto estilo Word (en línea,
// izquierda/derecha con texto alrededor, o centrada).
const imageWidth = () => editor.value?.getAttributes('image').imgWidth || '';
const setImageWidth = value => run(editor?.chain().setImageWidth(value || null));
const imageAlign = () => editor.value?.getAttributes('image').imgAlign || 'inline';
const setImageAlign = value => run(editor?.chain().setImageAlign(value));
const removeImage = () => run(editor?.chain().deleteSelection());

// Hoja y márgenes (persistidos): la hoja mantiene el ancho/alto elegidos y
// dibuja una línea sutil donde termina cada página.
const storageGet = key => { try { return window.localStorage?.getItem(key); } catch { return null; } };
const storageSet = (key, value) => { try { window.localStorage?.setItem(key, value); } catch {} };
const pageSizes = {
    A4: { width: '210mm', height: '297mm' },
    Carta: { width: '216mm', height: '279mm' },
    Oficio: { width: '216mm', height: '330mm' },
};
const pageMargins = {
    Estrecho: { padY: '12mm', padX: '12mm' },
    Normal: { padY: '22mm', padX: '20mm' },
    Ancho: { padY: '30mm', padX: '28mm' },
};
const pageSize = ref(storageGet('pdoc-page-size') || 'A4');
const pageMargin = ref(storageGet('pdoc-page-margins') || 'Normal');
if (!pageSizes[pageSize.value]) pageSize.value = 'A4';
if (!pageMargins[pageMargin.value]) pageMargin.value = 'Normal';
const pageStyle = computed(() => ({
    '--pd-page-w': pageSizes[pageSize.value].width,
    '--pd-page-h': pageSizes[pageSize.value].height,
    '--pd-page-px': pageMargins[pageMargin.value].padX,
    '--pd-page-py': pageMargins[pageMargin.value].padY,
}));
const pageEstimate = ref(1);
const updatePageEstimate = () => {
    const dom = editor.value?.view.dom;
    const mm = parseFloat(pageSizes[pageSize.value].height);
    if (!dom || !mm) { pageEstimate.value = 1; return; }
    pageEstimate.value = Math.max(1, Math.round(dom.scrollHeight / (mm * 3.7795275591)));
};
const setPageSize = value => { pageSize.value = value; storageSet('pdoc-page-size', value); updatePageEstimate(); };
const setPageMargin = value => { pageMargin.value = value; storageSet('pdoc-page-margins', value); updatePageEstimate(); };
onMounted(() => updatePageEstimate());

onBeforeUnmount(() => {
    editor.value?.destroy();
    if (ownsYdoc) ydoc.destroy();
});
</script>

<template>
    <section class="document-editor-shell" :style="pageStyle">
        <div class="document-editor-toolbar">
            <div class="document-editor-toolbar-inner">
                <div class="toolbar-group">
                    <span class="toolbar-group-label">Historial</span>
                    <button class="editor-tool-button" type="button" title="Deshacer" :disabled="!editable" @click="run(editor?.chain().undo())"><Undo2 :size="16" /></button>
                    <button class="editor-tool-button" type="button" title="Rehacer" :disabled="!editable" @click="run(editor?.chain().redo())"><Redo2 :size="16" /></button>
                </div>
                <div class="toolbar-group">
                    <span class="toolbar-group-label">Fuente</span>
                    <label class="editor-select-wrap"><Type :size="14" /><select v-model="selectedFont" :disabled="!editable" title="Fuente" @change="run(editor?.chain().setFontFamily(selectedFont))"><option v-for="font in ['Arial', 'Calibri', 'Georgia', 'Times New Roman', 'Verdana', 'Courier New']" :key="font">{{ font }}</option></select><ChevronDown :size="13" /></label>
                    <label class="editor-select-wrap editor-size-select"><select v-model="selectedFontSize" :disabled="!editable" title="Tamaño" @change="run(editor?.chain().setMark('textStyle', { fontSize: selectedFontSize }))"><option v-for="size in [8, 9, 10, 11, 12, 14, 16, 18, 20, 24, 28, 32, 36, 48, 60, 72]" :key="size" :value="`${size}pt`">{{ size }}</option></select><ChevronDown :size="13" /></label>
                </div>
                <div class="toolbar-group">
                    <span class="toolbar-group-label">Formato</span>
                    <button class="editor-tool-button" :class="{ 'editor-tool-active': editor?.isActive('bold') }" type="button" title="Negrita" :disabled="!editable" @click="run(editor?.chain().toggleBold())"><Bold :size="16" /></button>
                    <button class="editor-tool-button" :class="{ 'editor-tool-active': editor?.isActive('italic') }" type="button" title="Cursiva" :disabled="!editable" @click="run(editor?.chain().toggleItalic())"><Italic :size="16" /></button>
                    <button class="editor-tool-button" :class="{ 'editor-tool-active': editor?.isActive('underline') }" type="button" title="Subrayado" :disabled="!editable" @click="run(editor?.chain().toggleUnderline())"><UnderlineIcon :size="16" /></button>
                    <button class="editor-tool-button" :class="{ 'editor-tool-active': editor?.isActive('strike') }" type="button" title="Tachado" :disabled="!editable" @click="run(editor?.chain().toggleStrike())"><Strikethrough :size="16" /></button>
                    <button class="editor-tool-button" :class="{ 'editor-tool-active': verticalAlignActive('sub') }" type="button" title="Subíndice" :disabled="!editable" @click="toggleVerticalAlign('sub')"><Subscript :size="16" /></button>
                    <button class="editor-tool-button" :class="{ 'editor-tool-active': verticalAlignActive('super') }" type="button" title="Superíndice" :disabled="!editable" @click="toggleVerticalAlign('super')"><Superscript :size="16" /></button>
                </div>
                <div class="toolbar-group">
                    <span class="toolbar-group-label">Color</span>
                    <label class="editor-color-button" title="Color de texto"><Palette :size="15" /><input v-model="selectedColor" type="color" :disabled="!editable" @change="run(editor?.chain().setColor(selectedColor))" /></label>
                    <label class="editor-color-button" title="Color de resaltado"><Highlighter :size="15" /><input v-model="selectedHighlight" type="color" :disabled="!editable" @change="run(editor?.chain().toggleHighlight({ color: selectedHighlight }))" /></label>
                </div>
                <div class="toolbar-group">
                    <span class="toolbar-group-label">Títulos</span>
                    <button v-for="item in [{ level: 0, label: 'P', title: 'Párrafo' }, { level: 1, label: 'H1', title: 'Título 1' }, { level: 2, label: 'H2', title: 'Título 2' }, { level: 3, label: 'H3', title: 'Título 3' }]" :key="item.level" class="editor-tool-button editor-heading-button" :class="{ 'editor-tool-active': headingActive(item.level) }" type="button" :title="item.title" :disabled="!editable" @click="setHeading(item.level)">{{ item.label }}</button>
                </div>
                <div class="toolbar-group">
                    <span class="toolbar-group-label">Alineación</span>
                    <button class="editor-tool-button" title="Izquierda" :disabled="!editable" @click="run(editor?.chain().setTextAlign('left'))"><AlignLeft :size="16" /></button>
                    <button class="editor-tool-button" title="Centro" :disabled="!editable" @click="run(editor?.chain().setTextAlign('center'))"><AlignCenter :size="16" /></button>
                    <button class="editor-tool-button" title="Derecha" :disabled="!editable" @click="run(editor?.chain().setTextAlign('right'))"><AlignRight :size="16" /></button>
                    <button class="editor-tool-button" title="Justificado" :disabled="!editable" @click="run(editor?.chain().setTextAlign('justify'))"><AlignJustify :size="16" /></button>
                </div>
                <div class="toolbar-group">
                    <span class="toolbar-group-label">Listas</span>
                    <button class="editor-tool-button" title="Viñetas" :disabled="!editable" @click="run(editor?.chain().toggleBulletList())"><List :size="16" /></button>
                    <button class="editor-tool-button" title="Numerada" :disabled="!editable" @click="run(editor?.chain().toggleOrderedList())"><ListOrdered :size="16" /></button>
                    <button class="editor-tool-button" title="Disminuir sangría" :disabled="!editable" @click="setIndent('0')"><IndentDecrease :size="16" /></button>
                    <button class="editor-tool-button" title="Aumentar sangría" :disabled="!editable" @click="setIndent('2em')"><IndentIncrease :size="16" /></button>
                </div>
                <div class="toolbar-group">
                    <span class="toolbar-group-label">Insertar</span>
                    <button class="editor-tool-button" type="button" title="Enlace" :disabled="!editable" @click="addLink"><LinkIcon :size="16" /></button>
                    <button class="editor-tool-button" type="button" title="Subir imagen" :disabled="!editable" @click="imageInput?.click()"><ImagePlus :size="16" /></button>
                    <button class="editor-tool-button" type="button" title="Imagen desde URL" :disabled="!editable" @click="addImageFromUrl"><FileImage :size="16" /></button>
                    <button class="editor-tool-button" type="button" title="Línea horizontal" :disabled="!editable" @click="run(editor?.chain().setHorizontalRule())"><Minus :size="16" /></button>
                    <input ref="imageInput" type="file" accept="image/*" class="hidden" @change="selectImage" />
                </div>
                <div class="toolbar-group">
                    <span class="toolbar-group-label">Bloques</span>
                    <button class="editor-tool-button" :class="{ 'editor-tool-active': editor?.isActive('taskList') }" type="button" title="Lista de tareas" :disabled="!editable" @click="run(editor?.chain().toggleTaskList())"><ListChecks :size="16" /></button>
                    <button class="editor-tool-button" :class="{ 'editor-tool-active': editor?.isActive('blockquote') }" type="button" title="Cita" :disabled="!editable" @click="run(editor?.chain().toggleBlockquote())"><Quote :size="16" /></button>
                    <button class="editor-tool-button" :class="{ 'editor-tool-active': editor?.isActive('codeBlock') }" type="button" title="Bloque de código" :disabled="!editable" @click="run(editor?.chain().toggleCodeBlock())"><Code2 :size="16" /></button>
                    <button class="editor-tool-button" :class="{ 'editor-tool-active': editor?.isActive('code') }" type="button" title="Código en línea" :disabled="!editable" @click="run(editor?.chain().toggleCode())"><Code :size="16" /></button>
                </div>
                <div class="toolbar-group">
                    <span class="toolbar-group-label">Tabla</span>
                    <button class="editor-tool-button" type="button" title="Insertar tabla 3x3" :disabled="!editable" @click="insertTable"><Table2 :size="16" /></button>
                    <button class="editor-tool-button" type="button" title="Fila arriba" :disabled="!editable" @click="run(editor?.chain().addRowBefore())"><ArrowUp :size="16" /></button>
                    <button class="editor-tool-button" type="button" title="Fila abajo" :disabled="!editable" @click="run(editor?.chain().addRowAfter())"><ArrowDown :size="16" /></button>
                    <button class="editor-tool-button" type="button" title="Columna izquierda" :disabled="!editable" @click="run(editor?.chain().addColumnBefore())"><ArrowLeft :size="16" /></button>
                    <button class="editor-tool-button" type="button" title="Columna derecha" :disabled="!editable" @click="run(editor?.chain().addColumnAfter())"><ArrowRight :size="16" /></button>
                    <button class="editor-tool-button" type="button" title="Eliminar fila" :disabled="!editable" @click="run(editor?.chain().deleteRow())"><Trash2 :size="16" /></button>
                    <button class="editor-tool-button" type="button" title="Eliminar columna" :disabled="!editable" @click="run(editor?.chain().deleteColumn())"><Trash2 :size="16" /></button>
                    <button class="editor-tool-button" type="button" title="Eliminar tabla" :disabled="!editable" @click="run(editor?.chain().deleteTable())"><X :size="16" /></button>
                    <button class="editor-tool-button" type="button" title="Combinar celdas" :disabled="!editable" @click="run(editor?.chain().mergeCells())"><TableCellsMerge :size="16" /></button>
                    <button class="editor-tool-button" type="button" title="Dividir celda" :disabled="!editable" @click="run(editor?.chain().splitCell())"><TableCellsSplit :size="16" /></button>
                    <button class="editor-tool-button" :class="{ 'editor-tool-active': editor?.isActive('tableHeader') }" type="button" title="Fila de encabezado" :disabled="!editable" @click="run(editor?.chain().toggleHeaderRow())"><TableProperties :size="16" /></button>
                </div>
                <div v-if="editor?.isActive('image')" class="toolbar-group">
                    <span class="toolbar-group-label">Imagen</span>
                    <label class="editor-select-wrap" title="Tamaño de imagen"><select :value="imageWidth()" :disabled="!editable" @change="setImageWidth($event.target.value)"><option value="">Original</option><option value="25%">25%</option><option value="50%">50%</option><option value="75%">75%</option><option value="100%">100%</option></select><ChevronDown :size="13" /></label>
                    <button class="editor-tool-button" :class="{ 'editor-tool-active': imageAlign() === 'inline' }" type="button" title="En línea con el texto" :disabled="!editable" @click="setImageAlign('inline')"><TextWrap :size="16" /></button>
                    <button class="editor-tool-button" :class="{ 'editor-tool-active': imageAlign() === 'left' }" type="button" title="Ajustar a la izquierda (el texto rodea la imagen)" :disabled="!editable" @click="setImageAlign('left')"><AlignLeft :size="16" /></button>
                    <button class="editor-tool-button" :class="{ 'editor-tool-active': imageAlign() === 'center' }" type="button" title="Centrar imagen" :disabled="!editable" @click="setImageAlign('center')"><AlignCenter :size="16" /></button>
                    <button class="editor-tool-button" :class="{ 'editor-tool-active': imageAlign() === 'right' }" type="button" title="Ajustar a la derecha (el texto rodea la imagen)" :disabled="!editable" @click="setImageAlign('right')"><AlignRight :size="16" /></button>
                    <button class="editor-tool-button" type="button" title="Eliminar imagen" :disabled="!editable" @click="removeImage"><ImageMinus :size="16" /></button>
                </div>
                <div class="toolbar-group">
                    <span class="toolbar-group-label">Página</span>
                    <label class="editor-select-wrap" title="Tamaño de hoja"><select :value="pageSize" :disabled="!editable" @change="setPageSize($event.target.value)"><option v-for="name in Object.keys(pageSizes)" :key="name" :value="name">{{ name }}</option></select><ChevronDown :size="13" /></label>
                    <label class="editor-select-wrap" title="Márgenes"><select :value="pageMargin" :disabled="!editable" @change="setPageMargin($event.target.value)"><option v-for="name in Object.keys(pageMargins)" :key="name" :value="name">{{ name }}</option></select><ChevronDown :size="13" /></label>
                </div>
                <div class="toolbar-group">
                    <span class="toolbar-group-label">Más</span>
                    <button class="editor-tool-button" type="button" title="Limpiar formato" :disabled="!editable" @click="clearFormatting"><Eraser :size="16" /></button>
                </div>
            </div>
        </div>

        <div class="document-editor-canvas">
            <div class="document-editor-actions">
                <span class="document-editor-word-count">{{ wordCount }} palabras · ≈{{ pageEstimate }} pág.</span>
                <div class="flex gap-2">
                    <button type="button" class="editor-save-button" @click="emit('save-request')">Guardar</button>
                    <a v-if="exportPdfUrl" :href="exportPdfUrl" target="_blank" rel="noopener" class="editor-export-button">Exportar PDF</a>
                    <button v-else type="button" class="editor-export-button" @click="emit('export-pdf')">Exportar PDF</button>
                    <a v-if="exportDocxUrl" :href="exportDocxUrl" class="editor-export-button">Exportar Word</a>
                    <button v-else type="button" class="editor-export-button" @click="emit('export-docx')">Exportar Word</button>
                </div>
            </div>
            <EditorContent :editor="editor" class="document-editor-page" />
        </div>
    </section>
</template>

<style>
.document-editor-shell { min-width: 0; color: var(--pd-text); }
.document-editor-toolbar { position: sticky; top: 4rem; z-index: 20; border-bottom: 1px solid var(--pd-border); background: rgba(255, 255, 255, 0.85); box-shadow: 0 4px 14px rgb(15 23 42 / 7%); backdrop-filter: blur(10px); }
.dark .document-editor-toolbar { background: rgba(22, 35, 59, 0.85); }
.document-editor-toolbar-inner { display: flex; max-width: 1440px; margin: 0 auto; gap: 0.5rem; overflow-x: auto; padding: 0.55rem 1rem; }
.toolbar-group { display: flex; flex: 0 0 auto; align-items: center; gap: 0.15rem; border-right: 1px solid var(--pd-border); padding-right: 0.55rem; }
.toolbar-group:last-child { border-right: 0; }
.toolbar-group-label { align-self: flex-start; margin-right: 0.25rem; color: var(--pd-text-4); font-size: 0.6rem; font-weight: 700; letter-spacing: 0.07em; text-transform: uppercase; }
.editor-tool-button { display: inline-flex; height: 2rem; min-width: 2rem; align-items: center; justify-content: center; border-radius: 0.45rem; color: var(--pd-text-2); transition: background-color 120ms ease, color 120ms ease, box-shadow 120ms ease; }
.editor-tool-button:hover:not(:disabled) { background: var(--pd-surface-3); color: var(--pd-text); box-shadow: inset 0 0 0 1px var(--pd-border); }
.editor-tool-button:disabled { cursor: not-allowed; opacity: 0.42; }
.editor-tool-active { background: linear-gradient(135deg, #dbeafe, #e0e7ff); color: #1d4ed8; box-shadow: inset 0 0 0 1px #bfdbfe; }
.editor-heading-button { min-width: 2.2rem; font-size: 0.7rem; font-weight: 700; }
.editor-select-wrap { display: inline-flex; height: 2rem; align-items: center; gap: 0.15rem; border: 1px solid var(--pd-border); border-radius: 0.45rem; background: var(--pd-surface); padding: 0 0.35rem; color: var(--pd-text-3); box-shadow: var(--pd-shadow-sm); }
.editor-select-wrap select { max-width: 8.5rem; border: 0; background: transparent; padding: 0 0.1rem; font-size: 0.75rem; outline: none; }
.editor-size-select select { width: 3.2rem; }
.editor-color-button { position: relative; display: inline-flex; height: 2rem; width: 2rem; align-items: center; justify-content: center; overflow: hidden; border: 1px solid var(--pd-border); border-radius: 0.45rem; color: var(--pd-text-2); box-shadow: var(--pd-shadow-sm); }
.editor-color-button input { position: absolute; inset: 0; height: 100%; width: 100%; cursor: pointer; opacity: 0; }
.document-editor-canvas { min-height: calc(100vh - 8rem); background: var(--pd-preview); padding: 1rem 1rem 3rem; }
.document-editor-actions { display: flex; max-width: 210mm; margin: 0 auto 0.75rem; align-items: center; justify-content: space-between; gap: 1rem; }
.document-editor-word-count { color: var(--pd-text-3); font-size: 0.75rem; }
.editor-export-button { border: 1px solid var(--pd-border); border-radius: 0.5rem; background: var(--pd-surface); padding: 0.42rem 0.75rem; color: var(--pd-text-2); font-size: 0.75rem; font-weight: 600; box-shadow: var(--pd-shadow-sm); transition: border-color 120ms ease, box-shadow 120ms ease, transform 120ms ease; }
.editor-export-button:hover { border-color: var(--pd-accent); box-shadow: 0 4px 12px rgb(37 99 235 / 14%); transform: translateY(-1px); }
.editor-save-button { border: 1px solid #1d4ed8; border-radius: 0.5rem; background: linear-gradient(90deg, #0ea5e9, #2563eb); padding: 0.42rem 0.9rem; color: white; font-size: 0.75rem; font-weight: 700; box-shadow: var(--pd-shadow); transition: box-shadow 120ms ease, transform 120ms ease; }
.editor-save-button:hover { box-shadow: 0 10px 22px rgb(37 99 235 / 32%); transform: translateY(-1px); }
.document-editor-page .ProseMirror { width: min(var(--pd-page-w, 210mm), 100%); min-height: var(--pd-page-h, 297mm); margin: 0 auto; border-top: 1px solid rgb(255 255 255 / 90%); border-radius: 3px; padding: var(--pd-page-py, 22mm) var(--pd-page-px, 20mm); background-color: white; background-image: repeating-linear-gradient(to bottom, transparent 0 calc(var(--pd-page-h, 297mm) - 2px), rgb(15 23 42 / 13%) calc(var(--pd-page-h, 297mm) - 2px) var(--pd-page-h, 297mm)); box-shadow: var(--pd-shadow-lg), 0 0 0 1px rgb(15 23 42 / 4%); color: #1f2937; outline: none; font-family: Arial, sans-serif; font-size: 12pt; line-height: 1.2; white-space: pre-wrap; tab-size: 8; word-break: normal; }
.document-editor-page .ProseMirror p { margin: 0; }
.document-editor-page .ProseMirror p.is-editor-empty:first-child::before { float: left; height: 0; color: var(--pd-text-4); content: attr(data-placeholder); pointer-events: none; }
.document-editor-page .ProseMirror h1 { margin: 0.5em 0 0.3em; font-size: 24pt; line-height: 1.2; }
.document-editor-page .ProseMirror h2 { margin: 0.45em 0 0.25em; font-size: 18pt; line-height: 1.22; }
.document-editor-page .ProseMirror h3 { margin: 0.4em 0 0.2em; font-size: 14pt; line-height: 1.25; }
.document-editor-page .ProseMirror ul, .document-editor-page .ProseMirror ol { margin: 0; padding-left: 1.5rem; }
.document-editor-page .ProseMirror ul { list-style: disc; }
.document-editor-page .ProseMirror ol { list-style: decimal; }
.document-editor-page .ProseMirror ol[type='a'] { list-style-type: lower-alpha; }
.document-editor-page .ProseMirror ol[type='A'] { list-style-type: upper-alpha; }
.document-editor-page .ProseMirror ol[type='i'] { list-style-type: lower-roman; }
.document-editor-page .ProseMirror ol[type='I'] { list-style-type: upper-roman; }
.document-editor-page .ProseMirror li { margin: 0; }
.document-editor-page .ProseMirror a { color: #2563eb; text-decoration: underline; }
.document-editor-page .ProseMirror code { border-radius: 0.25rem; background: var(--pd-surface-3); padding: 0.1em 0.35em; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.85em; }
.document-editor-page .ProseMirror pre { margin: 0.75em 0; overflow-x: auto; border-radius: 0.4rem; background: #0f172a; padding: 0.85rem 1rem; white-space: pre-wrap; }
.document-editor-page .ProseMirror pre code { background: transparent; padding: 0; color: var(--pd-surface-3); font-size: 0.9em; }
.document-editor-page .ProseMirror ul[data-type='taskList'] { list-style: none; padding-left: 0.25rem; }
.document-editor-page .ProseMirror ul[data-type='taskList'] li { display: flex; align-items: flex-start; gap: 0.5rem; }
.document-editor-page .ProseMirror ul[data-type='taskList'] li > label { margin-top: 0.18rem; }
.document-editor-page .ProseMirror ul[data-type='taskList'] li > div { flex: 1; }
.document-editor-page .ProseMirror blockquote { margin: 0.6em 0; border-left: 3px solid var(--pd-border-strong); color: #475569; padding-left: 1rem; }
.document-editor-page .ProseMirror img { max-width: 100%; height: auto; }
.document-editor-page .ProseMirror table { width: 100%; border-collapse: collapse; table-layout: fixed; }
.document-editor-page .ProseMirror td, .document-editor-page .ProseMirror th { border: 1px solid var(--pd-text-4); padding: 0.45rem; vertical-align: top; }
.document-editor-page .ProseMirror th { background: var(--pd-surface-3); font-weight: 700; }
@media (max-width: 640px) { .document-editor-toolbar-inner { padding-inline: 0.5rem; } .document-editor-canvas { padding-inline: 0.35rem; } .document-editor-page .ProseMirror { min-height: 240mm; padding: 14mm 10mm; } .document-editor-actions { align-items: flex-start; flex-direction: column; } }
</style>
