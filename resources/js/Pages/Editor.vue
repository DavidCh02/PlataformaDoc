<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import * as Y from 'yjs';
import { ArrowLeft, ChevronLeft, ChevronRight, RefreshCw } from 'lucide-vue-next';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DocumentEditor from '@/Components/DocumentEditor.vue';

const props = defineProps({
    document: { type: Object, required: true },
    canEdit: { type: Boolean, default: false },
    siblings: { type: Array, default: () => [] },
});

const page = usePage();
const title = ref(props.document.title);
const content = ref(props.document.content || '<p></p>');
const saveState = ref('Guardado');
const hasPendingChanges = ref(false);
const presenceUsers = ref([]);
const denied = ref(false);
const ydoc = new Y.Doc();
let saveTimer;
let saveInterval;
let saveInFlight = false;

const currentUser = page.props.auth?.user;

const currentIndex = computed(() => props.siblings.findIndex(sibling => sibling.id === props.document.id));
const prevSibling = computed(() => currentIndex.value > 0 ? props.siblings[currentIndex.value - 1] : null);
const nextSibling = computed(() => currentIndex.value >= 0 && currentIndex.value < props.siblings.length - 1 ? props.siblings[currentIndex.value + 1] : null);
const goToEditor = sibling => router.visit(route('documents.edit', sibling.id), { preserveScroll: true });

const markSaveError = (error) => {
    if (error?.response?.status === 403) {
        denied.value = true;
        saveState.value = 'Sin permiso de edición';
        return;
    }
    saveState.value = 'Error al guardar';
};

const save = async () => {
    if (!props.canEdit || denied.value || saveInFlight) return;
    saveInFlight = true;
    const savedTitle = title.value;
    const savedContent = content.value;
    try {
        await axios.patch(route('documents.update', props.document.id), { title: savedTitle, content: savedContent });
        if (title.value === savedTitle && content.value === savedContent) {
            saveState.value = 'Guardado';
            hasPendingChanges.value = false;
        }
    } catch (error) {
        markSaveError(error);
    } finally {
        saveInFlight = false;
        if (hasPendingChanges.value && !denied.value) saveTimer = setTimeout(save, 0);
    }
};

const saveNow = () => {
    clearTimeout(saveTimer);
    return save();
};

const scheduleSave = () => {
    hasPendingChanges.value = true;
    saveState.value = 'Guardando...';
    clearTimeout(saveTimer);
    saveTimer = setTimeout(save, 900);
};
const updateContent = value => { content.value = value; scheduleSave(); };
const sendPendingChanges = () => {
    if (!props.canEdit || !hasPendingChanges.value || !navigator.sendBeacon) return;
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const data = new FormData();
    data.append('_method', 'PATCH');
    if (token) data.append('_token', token);
    data.append('title', title.value);
    data.append('content', content.value);
    navigator.sendBeacon(route('documents.update', props.document.id), data);
    hasPendingChanges.value = false;
};
const syncUpdate = async update => {
    if (!props.canEdit || denied.value) return;
    try {
        await axios.post(route('documents.sync', props.document.id), { content: update });
    } catch (error) {
        markSaveError(error);
    }
};

const refreshSync = () => {
    if (hasPendingChanges.value && !window.confirm('Tienes cambios sin guardar. ¿Recargar y descartarlos?')) return;
    router.reload({ preserveScroll: true });
};

const echoChannel = window.Echo?.private(`document.${props.document.id}`);
echoChannel?.listen('.DocumentUpdated', event => {
    if (event.user_id === currentUser?.id || typeof event.content !== 'string') return;
    if (event.content !== content.value) content.value = event.content;
});
const currentUserId = Number(currentUser?.id);
window.Echo?.join(`presence-document.${props.document.id}`)
    .here(members => { presenceUsers.value = members.filter(member => Number(member.id) !== currentUserId); })
    .joining(member => {
        if (Number(member.id) === currentUserId) return;
        if (!presenceUsers.value.some(item => item.id === member.id)) presenceUsers.value.push(member);
    })
    .leaving(member => { presenceUsers.value = presenceUsers.value.filter(item => item.id !== member.id); });

const uploadImage = async file => new Promise(resolve => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.readAsDataURL(file);
});

const handlePageExit = () => sendPendingChanges();
window.addEventListener('pagehide', handlePageExit);
window.addEventListener('beforeunload', handlePageExit);
saveInterval = setInterval(() => {
    if (hasPendingChanges.value) saveNow();
}, 30000);
router.on('before', () => {
    if (hasPendingChanges.value) sendPendingChanges();
});

onBeforeUnmount(() => {
    clearTimeout(saveTimer);
    clearInterval(saveInterval);
    window.removeEventListener('pagehide', handlePageExit);
    window.removeEventListener('beforeunload', handlePageExit);
    sendPendingChanges();
    echoChannel?.stopListening('.DocumentUpdated');
    window.Echo?.leave(`presence-document.${props.document.id}`);
    ydoc.destroy();
});
</script>

<template>
    <Head :title="title" />
    <AuthenticatedLayout>
        <template #header>
<div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex min-w-0 items-center gap-2">
                        <Link :href="route('dashboard')" title="Volver al explorador" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:border-sky-300 hover:bg-slate-50"><ArrowLeft :size="16" /></Link>
                        <button type="button" title="Documento anterior" :disabled="!prevSibling" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:border-sky-300 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40" @click="goToEditor(prevSibling)"><ChevronLeft :size="16" /></button>
                        <button type="button" title="Documento siguiente" :disabled="!nextSibling" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:border-sky-300 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40" @click="goToEditor(nextSibling)"><ChevronRight :size="16" /></button>
                        <span class="text-slate-300">/</span>
                        <input v-model="title" :disabled="!canEdit || denied" class="min-w-0 border-0 bg-transparent p-0 text-xl font-semibold text-slate-900 focus:ring-0" @input="scheduleSave" />
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm" :class="saveState.includes('permiso') || saveState.includes('Error') ? 'font-semibold text-red-600' : saveState !== 'Guardado' ? 'font-medium text-amber-600' : 'text-slate-500'">{{ saveState }}</span>
                        <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-300 bg-white text-slate-600 shadow-sm transition hover:border-sky-300 hover:bg-slate-50" title="Recargar sincronización: vuelve a cargar el documento y restablece la colaboración en vivo" @click="refreshSync"><RefreshCw :size="15" /></button>
                        <div v-if="presenceUsers.length" class="flex items-center -space-x-2" title="Colaboradores conectados">
                            <span v-for="member in presenceUsers" :key="member.id" class="flex h-7 w-7 items-center justify-center rounded-full border-2 border-white text-xs font-semibold text-white" :style="{ backgroundColor: `hsl(${(Number(member.id) * 137) % 360} 70% 45%)` }" :title="`${member.name} está editando`">{{ member.name?.charAt(0)?.toUpperCase() }}</span>
                        </div>
                    </div>
                </div>
            </template>

            <div v-if="denied" class="mx-auto max-w-4xl px-4 pt-4">
                <div class="rounded-xl border border-amber-200/70 bg-amber-50/80 px-4 py-3 text-sm text-amber-800 shadow-sm backdrop-blur">
                    Ya no tienes permiso para editar este documento. Puedes consultarlo en modo lectura.
                </div>
            </div>
        <DocumentEditor
            v-model:content="content"
            :editable="canEdit && !denied"
            :collaboration-document="ydoc"
            :image-upload="uploadImage"
            :export-pdf-url="route('documents.export-pdf', document.id)"
            :export-docx-url="route('documents.export-docx', document.id)"
            @update:content="updateContent"
            @save-request="saveNow"
            @collaboration-update="syncUpdate"
        />
    </AuthenticatedLayout>
</template>
