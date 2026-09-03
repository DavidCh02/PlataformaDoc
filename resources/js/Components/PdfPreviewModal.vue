<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import * as pdfjsLib from 'pdfjs-dist';
import pdfjsWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfjsWorker;

const props = defineProps({
    file: { type: Object, required: true },
    url: { type: String, required: true },
});

const emit = defineEmits(['close']);
const canvasContainer = ref(null);
const loading = ref(true);
const errorMessage = ref('');
const zoomLevel = ref(100);
const currentPage = ref(1);
const totalPages = ref(0);

let pdfDocument = null;

const zoomIn = () => { zoomLevel.value = Math.min(200, zoomLevel.value + 20); renderPage(currentPage.value); };
const zoomOut = () => { zoomLevel.value = Math.max(50, zoomLevel.value - 20); renderPage(currentPage.value); };
const resetZoom = () => { zoomLevel.value = 100; renderPage(currentPage.value); };

const renderPage = async (pageNumber) => {
    if (!pdfDocument || !canvasContainer.value) return;

    const page = await pdfDocument.getPage(pageNumber);
    const viewport = page.getViewport({ scale: zoomLevel.value / 100 });
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d');

    canvas.width = viewport.width;
    canvas.height = viewport.height;
    canvas.className = 'mx-auto mb-4 block shadow-lg';

    canvasContainer.value.innerHTML = '';
    canvasContainer.value.appendChild(canvas);

    await page.render({ canvasContext: context, viewport }).promise;
};

const loadPdf = async () => {
    loading.value = true;
    errorMessage.value = '';
    await nextTick();

    try {
        const response = await fetch(props.url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/pdf' },
        });

        if (!response.ok) {
            throw new Error('No se pudo cargar el PDF.');
        }

        const buffer = await response.arrayBuffer();
        pdfDocument = await pdfjsLib.getDocument({ data: buffer }).promise;
        totalPages.value = pdfDocument.numPages;
        currentPage.value = 1;
        await renderPage(1);
    } catch (error) {
        errorMessage.value = error.message || 'No se pudo mostrar el PDF.';
    } finally {
        loading.value = false;
    }
};

const goToPage = async (page) => {
    if (page < 1 || page > totalPages.value) return;
    currentPage.value = page;
    await renderPage(page);
};

watch(() => props.file.id, loadPdf, { immediate: true });

onBeforeUnmount(() => {
    pdfDocument?.destroy();
    if (canvasContainer.value) canvasContainer.value.innerHTML = '';
});
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-2 sm:p-6" @click.self="emit('close')">
        <section class="flex h-[96vh] w-full max-w-6xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-6">
                <div class="min-w-0">
                    <h2 class="truncate font-semibold text-slate-900">{{ file.original_name }}</h2>
                    <p class="text-xs text-slate-500">Visor PDF integrado</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div v-if="totalPages > 1" class="flex items-center gap-1 rounded-md border border-slate-300 px-1 py-1">
                        <button type="button" class="rounded px-2 py-1 text-sm text-slate-700 hover:bg-slate-100" :disabled="currentPage <= 1" @click="goToPage(currentPage - 1)">‹</button>
                        <span class="min-w-16 px-2 text-center text-sm text-slate-700">{{ currentPage }} / {{ totalPages }}</span>
                        <button type="button" class="rounded px-2 py-1 text-sm text-slate-700 hover:bg-slate-100" :disabled="currentPage >= totalPages" @click="goToPage(currentPage + 1)">›</button>
                    </div>
                    <div class="flex items-center gap-1 rounded-md border border-slate-300 px-1 py-1">
                        <button type="button" class="rounded px-2 py-1 text-sm text-slate-700 hover:bg-slate-100" title="Alejar" @click="zoomOut">−</button>
                        <button type="button" class="min-w-10 rounded px-2 py-1 text-center text-sm font-medium text-slate-700 hover:bg-slate-100" title="Restablecer zoom" @click="resetZoom">{{ zoomLevel }}%</button>
                        <button type="button" class="rounded px-2 py-1 text-sm text-slate-700 hover:bg-slate-100" title="Acercar" @click="zoomIn">+</button>
                    </div>
                    <a :href="route('files.download', file.id)" class="inline-flex items-center gap-1 rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Descargar</a>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50" @click="emit('close')">Cerrar</button>
                </div>
            </header>
            <div class="min-h-0 flex-1 overflow-auto bg-slate-100 p-4">
                <p v-if="loading" class="py-16 text-center text-sm text-slate-500">Cargando PDF...</p>
                <p v-else-if="errorMessage" class="py-16 text-center text-sm text-red-600">{{ errorMessage }}</p>
                <div v-show="!loading && !errorMessage" ref="canvasContainer" class="flex flex-col items-center"></div>
            </div>
        </section>
    </div>
</template>
