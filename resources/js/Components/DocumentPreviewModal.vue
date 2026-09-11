<script setup>
import { computed, nextTick, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { renderAsync } from 'docx-preview';
import { Download, FileDown, FileUp, Lock, LockOpen, X, ZoomIn, ZoomOut } from 'lucide-vue-next';
import { usePermissions } from '@/composables/usePermissions';

const props = defineProps({
    document: { type: Object, required: true },
});

const emit = defineEmits(['close', 'upload', 'unlocked']);

const { can } = usePermissions();
const currentUserId = Number(usePage().props.auth?.user?.id);

const locked = computed(() => Boolean(props.document.is_locked));
const lockLabel = computed(() => {
    if (!props.document.is_locked) return 'Disponible';
    return props.document.locked_by ? `Bloqueado · ${props.document.locked_by}` : 'Bloqueado';
});
const canForceUnlock = computed(() => locked.value && (
    props.document.locked_by_id === currentUserId || can('docs.edit_realtime')
));
const docxUrl = computed(() => props.document.linked_file
    ? route('files.download', props.document.linked_file.id)
    : route('documents.export-docx', props.document.id));
const pdfUrl = computed(() => route('documents.export-pdf', props.document.id));
const hasContent = computed(() => Boolean(props.document.content && props.document.content.replace(/<[^>]+>/g, '').trim()));
const linkedDocx = computed(() => props.document.linked_file
    && /\.docx$/i.test(props.document.linked_file.original_name || ''));
const hasBinary = computed(() => Boolean(props.document.current_version || linkedDocx.value));

const forceUnlock = async () => {
    if (!window.confirm(
        '¿Liberar el bloqueo manualmente? Si la persona sigue editando en Word, sus cambios guardados serán rechazados.',
    )) return;
    try {
        await axios.post(route('documents.unlock', props.document.id));
        emit('unlocked');
    } catch {
        window.alert('No se pudo liberar el bloqueo. Comprueba tus permisos.');
    }
};
const uploadVersion = () => emit('upload', props.document);

const zoom = ref(100);
const zoomIn = () => { zoom.value = Math.min(150, zoom.value + 25); };
const zoomOut = () => { zoom.value = Math.max(50, zoom.value - 25); };
const resetZoom = () => { zoom.value = 100; };

const container = ref(null);
const loadingBinary = ref(true);
const binaryError = ref('');

const renderOptions = {
    className: 'docx-wrapper',
    inBreak: true,
    ignoreWidth: false,
    ignoreHeight: false,
    ignoreFonts: false,
    breakPages: true,
    ignoreLastRenderedPageBreak: false,
    experimental: true,
    trimXmlDeclaration: true,
    useBase64URL: true,
    renderHeaders: true,
    renderFooters: true,
    renderFootnotes: true,
    renderEndnotes: true,
    renderAsync: true,
};

let activeRenderId = 0;

const renderBinary = async () => {
    const currentId = ++activeRenderId;
    loadingBinary.value = true;
    binaryError.value = '';
    await nextTick();

    try {
        const response = await fetch(docxUrl.value, {
            credentials: 'same-origin',
            headers: { Accept: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' },
        });
        if (!response.ok) throw new Error('No se pudo cargar el documento Word.');
        const buffer = await response.arrayBuffer();
        if (currentId !== activeRenderId) return;
        if (container.value) {
            container.value.innerHTML = '';
            await renderAsync(buffer, container.value, null, renderOptions);
        }
    } catch (error) {
        if (currentId !== activeRenderId) return;
        binaryError.value = error.message || 'No se pudo mostrar el documento Word.';
    } finally {
        if (currentId === activeRenderId) {
            loadingBinary.value = false;
        }
    }
};

watch(() => props.document.id, () => {
    if (hasBinary.value) renderBinary();
}, { immediate: true });

const onKeydown = (event) => { if (event.key === 'Escape') emit('close'); };
onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => {
    activeRenderId++;
    window.removeEventListener('keydown', onKeydown);
    try {
        if (container.value) container.value.innerHTML = '';
    } catch (_) {}
});
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-2 sm:p-6" @click.self="emit('close')">
        <section class="flex h-[96vh] w-full max-w-6xl flex-col overflow-hidden rounded-lg bg-white dark:bg-slate-800 shadow-2xl">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 dark:border-slate-700 px-4 py-3 sm:px-6">
                <div class="min-w-0">
                    <h2 class="truncate font-semibold text-slate-900 dark:text-white">{{ document.title }}</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Vista previa fiel al último contenido guardado ·
                        <span class="font-semibold" :class="locked ? 'text-amber-600' : 'text-emerald-600'">
                            <Lock v-if="locked" :size="11" class="mr-0.5 inline" />
                            <LockOpen v-else :size="11" class="mr-0.5 inline" />
                            {{ lockLabel }}
                        </span>
                        <template v-if="document.current_version">
                            · v{{ document.current_version }}
                        </template>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <div class="flex items-center gap-1 rounded-md border border-slate-300 dark:border-slate-600 px-1 py-1">
                        <button type="button" class="rounded px-2 py-1 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700/60" title="Alejar" @click="zoomOut"><ZoomOut :size="15" /></button>
                        <button type="button" class="min-w-10 rounded px-2 py-1 text-center text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700/60" title="Restablecer zoom" @click="resetZoom">{{ zoom }}%</button>
                        <button type="button" class="rounded px-2 py-1 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700/60" title="Acercar" @click="zoomIn"><ZoomIn :size="15" /></button>
                    </div>
                    <button v-if="canForceUnlock" type="button"
                            class="inline-flex items-center gap-1 rounded-md border border-amber-300 px-3 py-2 text-sm font-medium text-amber-700 hover:bg-amber-50"
                            @click="forceUnlock">
                        <LockOpen :size="14" /> Liberar bloqueo
                    </button>
                    <button type="button" class="rounded-md border border-slate-300 dark:border-slate-600 px-3 py-2 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700/40" @click="emit('close')"><X :size="14" class="mr-1 inline" />Cerrar</button>
                </div>
            </header>

            <div class="preview-modal-scroll">
                <p v-if="!hasBinary && !hasContent" class="py-16 text-center text-sm text-slate-500">
                    Este documento todavía no tiene contenido guardado. Pulsa "Subir versión" con un archivo .docx para verlo aquí.
                </p>
                <div v-else-if="hasBinary">
                    <p v-if="loadingBinary" class="py-16 text-center text-sm text-slate-500">Cargando vista previa...</p>
                    <p v-else-if="binaryError" class="py-16 text-center text-sm text-red-600">{{ binaryError }}</p>
                    <div v-show="!loadingBinary && !binaryError" ref="container" class="docx-wrapper preview-docx-container" :style="{ zoom: zoom / 100 }"></div>
                </div>
                <div v-else class="preview-modal-paper-wrapper">
                    <div class="preview-modal-paper" :style="{ zoom: zoom / 100 }" v-html="document.content"></div>
                </div>
            </div>

            <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 px-4 py-3 sm:px-6">
                <p class="text-xs text-slate-500 dark:text-slate-400">Las descargas reflejan el último cambio guardado (no versiones anteriores).</p>
                <div class="flex flex-wrap items-center gap-2">
                    <a :href="docxUrl" class="inline-flex items-center gap-1 rounded-md border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700/40">
                        <FileDown :size="15" /> Descargar en DOCX
                    </a>
                    <a :href="pdfUrl" class="inline-flex items-center gap-1 rounded-md border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700/40">
                        <Download :size="15" /> Descargar PDF
                    </a>
                    <button v-if="can('docs.edit_realtime') || can('files.upload')" type="button"
                            class="inline-flex items-center gap-1 rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
                            @click="uploadVersion">
                        <FileUp :size="15" /> Subir versión
                    </button>
                </div>
            </footer>
        </section>
        </div>
    </Teleport>
</template>

<style>
.preview-modal-scroll { min-height: 0; flex: 1; overflow: auto; background: var(--pd-preview); }
.preview-modal-paper-wrapper { display: flex; justify-content: center; padding: 28px 20px; }
.preview-modal-paper { width: 100%; max-width: 794px; background: #fff; padding: 56px 64px; color: #1f2937; font-size: 0.92rem; line-height: 1.65; box-shadow: var(--pd-shadow-lg); min-height: 480px; }
.preview-modal-paper h1, .preview-modal-paper h2, .preview-modal-paper h3, .preview-modal-paper h4 { margin: 0.8em 0 0.4em; color: #0f172a; line-height: 1.3; }
.preview-modal-paper h1 { font-size: 1.6em; } .preview-modal-paper h2 { font-size: 1.35em; } .preview-modal-paper h3 { font-size: 1.15em; }
.preview-modal-paper p { margin-bottom: 0.7em; }
.preview-modal-paper ul, .preview-modal-paper ol { margin: 0.4em 0 0.8em 1.4em; }
.preview-modal-paper img { max-width: 100%; height: auto; }
.preview-modal-paper table { width: 100%; border-collapse: collapse; margin: 0.6em 0; }
.preview-modal-paper td, .preview-modal-paper th { border: 1px solid var(--pd-border-strong); padding: 0.4rem 0.6rem; }
.preview-modal-paper blockquote { border-left: 3px solid var(--pd-border-strong); padding-left: 0.9em; color: #475569; }
.preview-docx-container { background-color: var(--pd-preview) !important; padding: 20px; }
.preview-docx-container img { max-width: 100%; }
@media (max-width: 640px) { .preview-modal-paper { padding: 24px 18px; } }
</style>