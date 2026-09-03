<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import * as Y from 'yjs';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DocumentEditor from '@/Components/DocumentEditor.vue';

const props = defineProps({
    document: { type: Object, required: true },
    canEdit: { type: Boolean, default: false },
});

const page = usePage();
const title = ref(props.document.title);
const content = ref(props.document.content || '<p></p>');
const saveState = ref('Guardado');
const hasPendingChanges = ref(false);
const presenceUsers = ref([]);
const ydoc = new Y.Doc();
let saveTimer;
let saveInterval;
let saveInFlight = false;

const currentUser = page.props.auth?.user;

const save = async () => {
    if (!props.canEdit || saveInFlight) return;
    saveInFlight = true;
    const savedTitle = title.value;
    const savedContent = content.value;
    try {
        await axios.patch(route('documents.update', props.document.id), { title: savedTitle, content: savedContent });
        if (title.value === savedTitle && content.value === savedContent) {
            saveState.value = 'Guardado';
            hasPendingChanges.value = false;
        }
    } catch {
        saveState.value = 'Error al guardar';
    } finally {
        saveInFlight = false;
        if (hasPendingChanges.value) saveTimer = setTimeout(save, 0);
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
    if (!props.canEdit) return;
    try {
        await axios.post(route('documents.sync', props.document.id), { content: update });
    } catch {
        saveState.value = 'Error de sincronización';
    }
};

const echoChannel = window.Echo?.private(`document.${props.document.id}`);
echoChannel?.listen('.DocumentUpdated', event => {
    if (event.user_id !== currentUser?.id && typeof event.content === 'string') content.value = event.content;
});
window.Echo?.join(`presence-document.${props.document.id}`)
    .here(members => { presenceUsers.value = members; })
    .joining(member => { presenceUsers.value.push(member); })
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
                <div class="flex min-w-0 items-center gap-3">
                    <Link :href="route('dashboard')" class="text-sm text-slate-500 hover:text-slate-900">Explorador</Link>
                    <span class="text-slate-300">/</span>
                    <input v-model="title" :disabled="!canEdit" class="min-w-0 border-0 bg-transparent p-0 text-xl font-semibold text-slate-900 focus:ring-0" @input="scheduleSave" />
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-sm text-slate-500">{{ saveState }}</span>
                    <span v-for="member in presenceUsers" :key="member.id" class="flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold text-white" :style="{ backgroundColor: `hsl(${(Number(member.id) * 137) % 360} 70% 45%)` }" :title="member.name">
                        {{ member.name?.charAt(0)?.toUpperCase() }}
                    </span>
                </div>
            </div>
        </template>

        <DocumentEditor
            v-model:content="content"
            :editable="canEdit"
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
