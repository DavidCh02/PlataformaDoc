<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { renderAsync } from 'docx-preview';

const props = defineProps({
    url: { type: String, required: true },
});

const emit = defineEmits(['error', 'loaded']);
const container = ref(null);
const loading = ref(true);
const errorMessage = ref('');
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

    try {
        const response = await fetch(props.url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' },
        });
        if (!response.ok) throw new Error('No se pudo descargar el documento.');

        const arrayBuffer = await response.arrayBuffer();
        container.value.innerHTML = '';
        await renderAsync(arrayBuffer, container.value, null, renderOptions);
        emit('loaded');
    } catch (error) {
        errorMessage.value = error.message || 'No se pudo mostrar el documento.';
        emit('error', error);
    } finally {
        loading.value = false;
    }
};

onMounted(renderDocument);
onBeforeUnmount(() => {
    if (container.value) container.value.innerHTML = '';
});
</script>

<template>
    <div class="docx-viewer-shell">
        <div v-if="loading" class="py-16 text-center text-sm text-slate-500">Cargando vista previa...</div>
        <div v-else-if="errorMessage" class="py-16 text-center text-sm text-red-600">{{ errorMessage }}</div>
        <div ref="container" class="docx-wrapper docx-viewer-container"></div>
    </div>
</template>

<style>
.docx-viewer-shell { min-height: 70vh; overflow: auto; background: #f3f4f6; padding: 0; }
.docx-wrapper { background-color: #f3f4f6 !important; padding: 20px; }
.docx-wrapper > section.docx { box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5) !important; margin-bottom: 20px !important; }
.docx-wrapper header img, .docx-wrapper .docx-header img { display: inline-block !important; max-width: 100% !important; }
.docx-viewer-container { width: 100%; }
.docx-viewer-container section.docx { margin: 0 auto 1.5rem; box-shadow: 0 10px 25px rgb(15 23 42 / 12%); }
.docx-viewer-container img { max-width: 100%; }
@media (max-width: 640px) { .docx-viewer-shell { padding: 1rem 0.25rem; } }
</style>
