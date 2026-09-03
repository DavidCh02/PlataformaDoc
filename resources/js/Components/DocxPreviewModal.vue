<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { renderAsync } from 'docx-preview';

const props = defineProps({
    file: { type: Object, required: true },
    url: { type: String, default: '' },
    source: { type: [ArrayBuffer, Blob], default: null },
});

const emit = defineEmits(['close', 'edit', 'error']);
const container = ref(null);
const loading = ref(true);
const errorMessage = ref('');
const zoomLevel = ref(100);

const zoomIn = () => { zoomLevel.value = Math.min(200, zoomLevel.value + 20); };
const zoomOut = () => { zoomLevel.value = Math.max(50, zoomLevel.value - 20); };
const resetZoom = () => { zoomLevel.value = 100; };
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

const renderDocument = async () => {
    loading.value = true;
    errorMessage.value = '';
    await nextTick();

    try {
        let arrayBuffer = props.source;
        if (!arrayBuffer) {
            const response = await fetch(props.url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' },
            });
            if (!response.ok) throw new Error('No se pudo descargar el documento.');
            arrayBuffer = await response.arrayBuffer();
        }
        if (container.value) {
            container.value.innerHTML = '';
            const sourceBuffer = arrayBuffer instanceof Blob
                ? await arrayBuffer.arrayBuffer()
                : arrayBuffer;
            await renderAsync(sourceBuffer, container.value, null, renderOptions);
        }
    } catch (error) {
        errorMessage.value = error.message || 'No se pudo mostrar el documento.';
        emit('error', error);
    } finally {
        loading.value = false;
    }
};

watch(() => props.file.id, renderDocument, { immediate: true });
onBeforeUnmount(() => {
    if (container.value) container.value.innerHTML = '';
});
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-2 sm:p-6" @click.self="emit('close')">
        <section class="flex h-[96vh] w-full max-w-6xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-6">
                <div class="min-w-0">
                    <h2 class="truncate font-semibold text-slate-900">{{ file.original_name }}</h2>
                    <p class="text-xs text-slate-500">Vista previa del archivo original</p>
                </div>
                <div class="flex items-center gap-2">
                    <div class="flex items-center gap-1 rounded-md border border-slate-300 px-1 py-1">
                        <button type="button" class="rounded px-2 py-1 text-sm text-slate-700 hover:bg-slate-100" title="Alejar" @click="zoomOut">−</button>
                        <button type="button" class="min-w-10 rounded px-2 py-1 text-center text-sm font-medium text-slate-700 hover:bg-slate-100" title="Restablecer zoom" @click="resetZoom">{{ zoomLevel }}%</button>
                        <button type="button" class="rounded px-2 py-1 text-sm text-slate-700 hover:bg-slate-100" title="Acercar" @click="zoomIn">+</button>
                    </div>
                    <a :href="route('files.download', file.id)" class="inline-flex items-center gap-1 rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Descargar original</a>
                    <button type="button" class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700" @click="emit('edit')">Editar en Plataforma</button>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50" @click="emit('close')">Cerrar</button>
                </div>
            </header>
            <div class="docx-preview-scroll">
                <p v-if="loading" class="py-16 text-center text-sm text-slate-500">Cargando vista previa...</p>
                <p v-else-if="errorMessage" class="py-16 text-center text-sm text-red-600">{{ errorMessage }}</p>
                <div ref="container" class="docx-wrapper docx-preview-container" :style="{ zoom: zoomLevel / 100 }"></div>
            </div>
        </section>
    </div>
</template>

<style>
.docx-preview-scroll { min-height: 0; flex: 1; overflow: auto; background: #f3f4f6; }
.docx-wrapper { background-color: #f3f4f6 !important; padding: 20px; }
.docx-wrapper > section.docx { box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5) !important; margin-bottom: 20px !important; }
.docx-wrapper header img, .docx-wrapper .docx-header img { display: inline-block !important; max-width: 100% !important; }
.docx-preview-container { width: 100%; }
.docx-preview-container img { max-width: 100%; }
@media (max-width: 640px) { .docx-preview-scroll { padding: 1rem 0.25rem; } }
</style>
