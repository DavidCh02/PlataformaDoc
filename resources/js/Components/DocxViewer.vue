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
        <div v-if="loading" class="py-16 text-center text-sm text-slate-500 dark:text-slate-400">Cargando vista previa...</div>
        <div v-else-if="errorMessage" class="py-16 text-center text-sm text-red-600">{{ errorMessage }}</div>
        <div ref="container" class="docx-wrapper docx-viewer-container"></div>
    </div>
</template>

<style>
.docx-viewer-shell { min-height: 70vh; overflow: auto; background: var(--pd-preview); padding: 0; }
.docx-wrapper-wrapper > section.docx-wrapper, .docx-viewer-shell section.docx-wrapper { box-shadow: var(--pd-shadow-lg) !important; margin-bottom: 20px !important; }
.docx-wrapper header img, .docx-wrapper .docx-header img { display: inline-block !important; max-width: 100% !important; }
.docx-viewer-container { width: 100%; }
.docx-viewer-container section.docx-wrapper { margin: 0 auto 1.5rem; box-shadow: var(--pd-shadow-sm); }
.docx-viewer-container img { max-width: 100%; }
@media (max-width: 640px) { .docx-viewer-shell { padding: 1rem 0.25rem; } }
</style>
