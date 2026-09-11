<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { RefreshCw, Folder, FolderPlus, FileText, File, FilePlus, Image, Eye, History, Upload, Download, Trash2, RotateCcw, Pencil, ChevronLeft, ChevronRight } from 'lucide-vue-next';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import FolderTreeItem from '@/Components/FolderTreeItem.vue';
import DocxPreviewModal from '@/Components/DocxPreviewModal.vue';
import DocumentPreviewModal from '@/Components/DocumentPreviewModal.vue';
import PdfPreviewModal from '@/Components/PdfPreviewModal.vue';
import { usePermissions } from '@/composables/usePermissions';

const props = defineProps({
    folders: { type: Array, required: true },
    files: { type: Array, required: true },
    documents: { type: Array, required: true },
    folderTree: { type: Array, default: () => [] },
    currentFolder: { type: [Number, null], default: null },
    breadcrumbs: { type: Array, default: () => [] },
    showTrash: { type: Boolean, default: false },
});

const showFolderModal = ref(false);
const importWarningItem = ref(null);
const selectedFile = ref(null);
const selectedDocument = ref(null);
const previewingDocument = ref(false);
const previewMode = ref(null);
const isDragging = ref(false);
const folderForm = useForm({ name: '', parent_id: null });
const uploadForm = useForm({ file: null, folder_id: null });
const { can } = usePermissions();
const currentUserId = Number(usePage().props.auth?.user?.id);

// Estado en tiempo real: los bloqueos de Word llegan por polling y se aplican
// al vuelo; si cambia la firma (contenido de documentos, versiones, carpetas o
// archivos) el Explorador se recarga solo para reflejar los últimos cambios en
// la tabla, el árbol de carpetas y la vista previa abierta.
const needsRefresh = ref(false);
const lockState = reactive({});
let lastSignature = null;
let pollTimer = null;
let isAutoReloading = false;
let lastAutoReloadAt = 0;

const folderIdFromUrl = () => {
    const value = new URLSearchParams(window.location.search).get('folder_id');
    return value ? Number(value) : null;
};

const refreshExplorer = () => {
    needsRefresh.value = false;
    router.reload({ preserveScroll: true });
};

const isLockedNow = item => item.kind === 'document' && (lockState[item.id]?.is_locked ?? item.is_locked);
const lockLabel = item => {
    const lock = lockState[item.id] || item;
    if (!isLockedNow(item)) return 'Disponible';
    return 'Bloqueado' + (lock.locked_by ? ` · ${lock.locked_by}` : '');
};

const pollExplorerState = async () => {
    try {
        const { data } = await axios.get(route('explorer.state'), { params: { folder_id: folderIdFromUrl() } });
        (data.documents || []).forEach(state => { lockState[state.id] = state; });

        // Mientras el modal "Modificar en Word" está abierto NO recargamos la
        // página: el reload que dispara la firma (al tomar/liberar el bloqueo)
        // destruiría el modal. Actualizamos la firma en silencio y dejamos que
        // el watch de cierre automático reaccione al desbloqueo (check-in del
        // Add-in o cancelación). Los badges se sigen actualizando en vivo.
        if (modifyItem.value) {
            lastSignature = data.signature;
            return;
        }

        if (lastSignature === null) { lastSignature = data.signature; return; }
        if (data.signature !== lastSignature) {
            lastSignature = data.signature;
            const now = Date.now();
            if (!isAutoReloading && now - lastAutoReloadAt > 2000) {
                isAutoReloading = true;
                lastAutoReloadAt = now;
                needsRefresh.value = false;
                router.reload({
                    preserveScroll: true,
                    onFinish: () => { isAutoReloading = false; },
                });
            } else {
                needsRefresh.value = true;
            }
        }
    } catch (e) {
        // Silencioso: el siguiente tic (8 s) reintenta por sí solo.
    }
};

// Subida manual de versión desde el panel (exclusión mutua con Word incluida:
// el servidor rechaza con 409 si el documento se está editando en Word).
const uploadVersionItem = ref(null);
const uploadVersionFile = ref(null);
const uploadVersionNotes = ref('');
const uploadVersionError = ref('');
const uploadingVersion = ref(false);
const canUploadVersions = () => can('docs.edit_realtime') || can('files.upload');

const openUploadVersion = item => {
    uploadVersionItem.value = item;
    uploadVersionFile.value = null;
    uploadVersionNotes.value = '';
    uploadVersionError.value = '';
};
const onUploadVersionFile = event => {
    uploadVersionFile.value = event.target.files?.[0] || null;
    uploadVersionError.value = '';
};
const submitUploadVersion = async () => {
    if (!uploadVersionItem.value || uploadingVersion.value) return;
    if (!uploadVersionFile.value) { uploadVersionError.value = 'Selecciona un archivo .docx o .doc.'; return; }
    uploadingVersion.value = true;
    uploadVersionError.value = '';
    const form = new FormData();
    form.append('file', uploadVersionFile.value);
    form.append('change_notes', uploadVersionNotes.value || '');
    try {
        await axios.post(route('documents.versions.store', uploadVersionItem.value.id), form);
        uploadVersionItem.value = null;
        needsRefresh.value = false;
        router.reload({ preserveScroll: true });
    } catch (err) {
        uploadVersionError.value = err.response?.data?.message || 'No se pudo subir la versión.';
    } finally {
        uploadingVersion.value = false;
    }
};

// "Modificar en Word" (fidelidad 100 %): el Explorador descarga el `.docx` real
// con la propiedad custom `plataforma_doc_id` inyectada, bloquea el documento
// para este usuario y abre un modal que aguarda: (a) la subida manual del
// archivo editado, o (b) que el Add-in de Word haga el Check-In (el modal se
// cierra solo al detectar el desbloqueo en el siguiente tic de `lockState`),
// o (c) "Cancelar" (libera el bloqueo y marca la edición como cancelada para
// que el Add-in rechace cualquier subida con el mensaje correspondiente).
const modifyItem = ref(null);
const modifyFile = ref(null);
const modifyNotes = ref('');
const modifyError = ref('');
const modifying = ref(false);
const modifyLocked = ref(false);
let modifyHeartbeatTimer = null;
let modifyOpenedAt = 0;

const modifierLockedByMe = item => {
    const lock = lockState[item.id] || item;
    return Number(lock?.locked_by_id) === currentUserId;
};

const readError = async err => {
    // responseType 'blob': los errores JSON del servidor llegan como Blob.
    const data = err.response?.data;
    if (data && typeof data.text === 'function') {
        const text = await data.text();
        if (text) {
            try { return JSON.parse(text).message || text; } catch (e) { return text; }
        }
    }
    return err.response?.data?.message || err.message;
};

const performModifyDownload = async item => {
    const response = await axios.post(route('documents.modify', item.id), {}, { responseType: 'blob' });
    const blob = response.data;
    const url = window.URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `${String(item.title || 'documento').replace(/[\\/:*?"<>|]/g, '-').trim() || 'documento'}.docx`;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    window.setTimeout(() => window.URL.revokeObjectURL(url), 10000);
    modifyLocked.value = true;
    lockState[item.id] = { id: item.id, is_locked: true, locked_by_id: currentUserId, locked_by: null, locked_at: null };
    startModifyHeartbeat();
};

const startModify = async item => {
    if (isLockedNow(item) && !modifierLockedByMe(item)) return;
    // Un doble clic abre la previsualización (primer clic) y al instante pide
    // "Modificar": cerramos la previsualización para que no queden dos modales
    // apilados y el botón Cerrar deje siempre la pantalla despejada.
    closePreview();
    modifyItem.value = item;
    modifyFile.value = null;
    modifyNotes.value = '';
    modifyError.value = '';
    modifyLocked.value = false;
    modifyOpenedAt = Date.now();
    modifying.value = true;
    try {
        await performModifyDownload(item);
    } catch (err) {
        modifyError.value = await readError(err) || 'No se pudo preparar el documento. Revisa que el archivo sea .docx y no esté bloqueado por otro usuario.';
    } finally {
        modifying.value = false;
    }
};

// Un archivo Word suelto (subido sin vincular) se convierte al momento en un
// documento editable y continúa con el flujo Modificar: descarga con metadatos
// + bloqueo + modal (el Add-in lo reconoce por metadatos).
const startModifyFile = async file => {
    if (isLockedNow(file) && !modifierLockedByMe(file)) return;
    closePreview();
    modifyItem.value = { ...file, kind: 'document', title: file.original_name, linked_file: true };
    modifyFile.value = null;
    modifyNotes.value = '';
    modifyError.value = '';
    modifyLocked.value = false;
    modifyOpenedAt = Date.now();
    modifying.value = true;
    try {
        let docId = file.document_id;
        if (!docId) {
            const res = await axios.post(route('files.link-word', file.id));
            docId = res.data?.document_id ?? res.data?.document?.id ?? null;
            if (!docId) throw new Error('No se pudo vincular el archivo a un documento editable.');
        }
        modifyItem.value.id = docId;
        await performModifyDownload(modifyItem.value);
    } catch (err) {
        modifyError.value = await readError(err) || 'No se pudo preparar el documento en Word.';
    } finally {
        modifying.value = false;
    }
};

const startModifyHeartbeat = () => {
    stopModifyHeartbeat();
    if (!modifyItem.value) return;
    modifyHeartbeatTimer = window.setInterval(async () => {
        if (!modifyItem.value) return;
        try {
            await axios.post(route('documents.modify-heartbeat', modifyItem.value.id));
        } catch (e) {
            // El watch sobre lockState cierra el modal si el bloqueo se liberó.
        }
    }, 60000);
};

const stopModifyHeartbeat = () => {
    if (modifyHeartbeatTimer) {
        window.clearInterval(modifyHeartbeatTimer);
        modifyHeartbeatTimer = null;
    }
};

const closeModify = () => {
    stopModifyHeartbeat();
    modifyItem.value = null;
    modifyLocked.value = false;
};

const cancelModify = async () => {
    const item = modifyItem.value;
    if (!item) return;
    modifying.value = true;
    modifyError.value = '';
    try {
        if (modifyLocked.value) {
            await axios.post(route('documents.modify-cancel', item.id));
        }
        lockState[item.id] = { id: item.id, is_locked: false, locked_by_id: null, locked_by: null, locked_at: null };
        closeModify();
        needsRefresh.value = false;
        router.reload({ preserveScroll: true });
    } catch (err) {
        modifyError.value = await readError(err) || 'No se pudo cancelar la edición.';
    } finally {
        modifying.value = false;
    }
};

const onModifyFile = event => {
    modifyFile.value = event.target.files?.[0] || null;
    modifyError.value = '';
};

const submitModify = async () => {
    if (!modifyItem.value || modifying.value) return;
    if (!modifyFile.value) { modifyError.value = 'Selecciona el archivo .docx modificado en Word.'; return; }
    modifying.value = true;
    modifyError.value = '';
    const form = new FormData();
    form.append('file', modifyFile.value);
    form.append('change_notes', modifyNotes.value || 'Modificado en Word (fidelidad 100 %).');
    try {
        await axios.post(route('documents.versions.store', modifyItem.value.id), form);
        closeModify();
        needsRefresh.value = false;
        router.reload({ preserveScroll: true });
    } catch (err) {
        modifyError.value = await readError(err) || 'No se pudo guardar la versión.';
    } finally {
        modifying.value = false;
    }
};

// Auto-cierre del modal cuando el Add-in de Word hace el Check-In: el polling
// de `explorer.state` refleja el desbloqueo y la vista pasa a "disponible".
//
// Gracia anti-carrera: un tic del polling que arrancó ANTES del clic en
// "Modificar" trae el estado previo (desbloqueado) y, si llega justo después
// de abrir el modal, parece un Check-In y lo cerraría solo/desaparecería en
// milisegundos. Por eso ignoramos los desbloqueos mientras se está descargando
// y durante la primera gracia tras abrir el modal: un Check-In real del Add-in
// tarda al menos esos segundos (abrir Word + editar + subir versión).
watch(() => (modifyItem.value ? lockState[modifyItem.value.id]?.is_locked : null), (isLocked) => {
    if (!modifyItem.value || modifying.value) return;
    if (isLocked === undefined || isLocked !== false) return;
    if (Date.now() - modifyOpenedAt < 10000) return;
    closeModify();
    refreshExplorer();
});

onMounted(() => {
    window.enableBroadcasting?.();
    if (!props.showTrash) {
        pollExplorerState();
        pollTimer = window.setInterval(pollExplorerState, 8000);
    }
});

// Vista previa en vivo del documento seleccionado.
const liveContent = reactive({});
let previewChannel = null;
let previewDocumentId = null;

const subscribePreviewLive = () => {
    if (previewDocumentId !== null) {
        window.Echo?.leave(`document.${previewDocumentId}`);
        previewChannel = null;
        previewDocumentId = null;
    }
    const id = selectedDocument.value?.id;
    if (id == null) return;
    previewDocumentId = id;
    const channel = window.Echo?.private(`document.${id}`);
    if (!channel) return;
    previewChannel = channel;
    channel.listen('.DocumentUpdated', event => {
        if (Number(event.user_id) === currentUserId) return;
        if (typeof event.content === 'string') liveContent[event.document_id ?? id] = event.content;
    });
};

watch(() => selectedDocument.value?.id, subscribePreviewLive);

// Tras una recarga automática, props.documents trae el contenido actualizado:
// re-sincronizamos la vista previa abierta para que refleje los últimos cambios
// sin que el usuario tenga que cerrarla y volver a abrirla.
watch(() => props.documents, (documents) => {
    const id = selectedDocument.value?.id;
    if (id == null) return;
    const fresh = documents.find(document => document.id === id);
    if (fresh) {
        selectedDocument.value = { ...fresh, content: liveContent[fresh.id] || fresh.content };
    } else {
        closePreview();
    }
});
onBeforeUnmount(() => {
    if (previewDocumentId !== null) window.Echo?.leave(`document.${previewDocumentId}`);
    if (pollTimer) window.clearInterval(pollTimer);
});

const currentFolderLabel = computed(() => props.breadcrumbs.at(-1)?.name || 'Este equipo');
const iconFor = item => {
    if (item.kind === 'folder') return Folder;
    if (item.kind === 'document') return FileText;
    const name = item.original_name?.toLowerCase() || '';
    return /\.(png|jpe?g|gif|webp|bmp|svg)$/.test(name) ? Image : File;
};
const typeLabel = item => {
    if (item.kind === 'folder') return 'Carpeta';
    if (item.kind === 'document') {
        if (item.linked_file) {
            const ext = (item.linked_file.original_name?.split('.').pop() || 'docx').toUpperCase();
            return `${ext} · editable`;
        }
        return 'Documento';
    }
    const ext = (item.original_name?.split('.').pop() || 'ARCHIVO').toUpperCase();
    return `${ext} · ${formatSize(item.file_size)}`;
};
const items = computed(() => [
    ...props.folders.map(folder => ({ ...folder, kind: 'folder', sort: 0, label: folder.name })),
    ...props.files.map(file => ({ ...file, kind: 'file', sort: 1, label: file.original_name })),
    ...props.documents.map(document => ({ ...document, kind: 'document', sort: 1, label: document.title })),
].sort((left, right) => left.sort - right.sort || left.label.localeCompare(right.label)));

const pageSize = 15;
const page = ref(0);
const pageCount = computed(() => Math.max(1, Math.ceil(items.value.length / pageSize)));
const visibleItems = computed(() => items.value.slice(page.value * pageSize, (page.value + 1) * pageSize));
watch([() => props.currentFolder, () => props.showTrash], () => { page.value = 0; });
watch(() => items.value.length, () => {
    if (page.value >= pageCount.value) page.value = pageCount.value - 1;
});

const openFolder = folderId => router.get(route('dashboard'), { folder_id: folderId });
const toggleTrash = () => router.get(route('dashboard'), { trash: !props.showTrash });
const createFolder = () => {
    const url = route('folders.store');
    const parentId = folderIdFromUrl();
    if (parentId) {
        folderForm.parent_id = parentId;
    } else {
        delete folderForm.parent_id;
    }
    folderForm.post(url, {
        preserveScroll: true,
        onSuccess: () => { folderForm.reset(); showFolderModal.value = false; },
        onError: () => {},
        onFinish: () => {},
    });
};
const selectFile = event => { selectedFile.value = event.target.files?.[0] || null; uploadForm.file = selectedFile.value; };
const uploadFile = () => {
    const url = route('files.store');
    const folderId = folderIdFromUrl();
    if (folderId) {
        uploadForm.folder_id = folderId;
    } else {
        delete uploadForm.folder_id;
    }
    uploadForm.post(url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { selectedFile.value = null; uploadForm.reset(); },
    });
};
const handleDrop = event => { isDragging.value = false; const [file] = event.dataTransfer.files; if (file) { selectedFile.value = file; uploadForm.file = file; } };
const showDocumentModal = ref(false);
const docForm = useForm({ title: '' });
const createDocument = () => {
    if (!docForm.title.trim()) return;
    docForm.transform(data => {
        const payload = { title: data.title.trim() };
        const folderId = folderIdFromUrl();
        if (folderId) payload.folder_id = folderId;
        return payload;
    }).post(route('documents.store'), {
        preserveScroll: true,
        onSuccess: () => { docForm.reset(); showDocumentModal.value = false; },
    });
};
const isPdf = file => file.mime_type === 'application/pdf' || file.original_name?.toLowerCase().endsWith('.pdf');
const isDocx = file => file.original_name?.toLowerCase().endsWith('.docx');
const isWordFile = file => isDocx(file) || file.original_name?.toLowerCase().endsWith('.doc');
// Primera edición de una fuente importada de Word: aún no se ha editado nunca.
const needsImportWarning = item => {
    if (item.kind === 'file') return isWordFile(item);
    return Boolean(item.imported_from) && !item.first_edited_at;
};
// Documentos nacidos en el editor de la plataforma (no subidos/importados):
// tienen binario/v1 desde su creación y pueden volver al editor.
const isPlatformDoc = item => item.kind === 'document' && Boolean(item.platform_created);
const canEditItem = item => can('docs.edit_realtime') || Number(item.user_id) === currentUserId;
const requestEdit = item => {
    if (item.kind === 'document' && needsImportWarning(item) && !isPlatformDoc(item)) {
        importWarningItem.value = item;
        return;
    }
    router.visit(route(`${item.kind}s.edit`, item.id));
};
// Los archivos Word se editan con el Add-in a través del flujo Modificar
// (startModifyFile, declarado arriba): vincula el archivo a un documento
// editable si hace falta y descarga el binario con metadatos + bloqueo + modal.
const openHistory = item => {
    if (item.kind === 'document') router.visit(route('documents.history', item.id));
};
const proceedWithEdit = () => {
    const item = importWarningItem.value;
    if (!item) return;
    importWarningItem.value = null;
    router.visit(route(`${item.kind}s.edit`, item.id));
};
const previewFile = file => { if (modifyItem.value) return; previewingDocument.value = false; selectedDocument.value = null; if (isPdf(file)) { previewMode.value = 'pdf'; selectedFile.value = file; } else if (isDocx(file)) { previewMode.value = 'docx'; selectedFile.value = file; } };
const previewDocument = document => {
    if (modifyItem.value) return;
    selectedDocument.value = { ...document, content: liveContent[document.id] || document.content };
    selectedFile.value = null;
    previewMode.value = null;
    previewingDocument.value = true;
};
const closePreview = () => {
    selectedFile.value = null;
    selectedDocument.value = null;
    previewMode.value = null;
    previewingDocument.value = false;
};
const onPreviewUpload = document => {
    const copy = { ...document };
    closePreview();
    openUploadVersion(copy);
};
const onPreviewUnlocked = () => {
    if (selectedDocument.value?.id) {
        lockState[selectedDocument.value.id] = {
            ...(lockState[selectedDocument.value.id] || {}),
            is_locked: false,
            locked_by: null,
            locked_by_id: null,
        };
    }
    needsRefresh.value = false;
    router.reload({ preserveScroll: true });
};
const onRowDblClick = item => {
    if (item.kind === 'folder') return openFolder(item.id);
    if (item.kind === 'document') return previewDocument(item);
    if (isWordFile(item)) return startModifyFile(item);
    if (isPdf(item) || isDocx(item)) previewFile(item);
};
const pendingDelete = ref(null);
const pendingForceDelete = ref(null);
const showEmptyTrash = ref(false);
const askRemove = item => { pendingDelete.value = { kind: `${item.kind}s`, id: item.id, label: item.label }; };
const confirmRemove = () => {
    if (!pendingDelete.value) return;
    router.delete(route(`${pendingDelete.value.kind}.destroy`, pendingDelete.value.id), {
        preserveScroll: true,
        onFinish: () => { pendingDelete.value = null; },
    });
};
const restore = (kind, id) => router.post(route(`${kind}.restore`, id), {}, { preserveScroll: true });
const askForceDelete = item => { pendingForceDelete.value = { kind: `${item.kind}s`, id: item.id, label: item.label }; };
const confirmForceDelete = () => {
    if (!pendingForceDelete.value) return;
    router.delete(route(`${pendingForceDelete.value.kind}.force-destroy`, pendingForceDelete.value.id), {
        preserveScroll: true,
        onFinish: () => { pendingForceDelete.value = null; },
    });
};
const emptyTrash = () => {
    router.post(route('trash.empty'), {}, {
        preserveScroll: true,
        onFinish: () => { showEmptyTrash.value = false; },
    });
};
const formatSize = bytes => {
    if (!bytes) return '0 KB';
    const units = ['B', 'KB', 'MB', 'GB'];
    const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    return `${(bytes / 1024 ** index).toFixed(index ? 1 : 0)} ${units[index]}`;
};
const ownerName = item => item.user?.name || 'Usuario desconocido';
const modifierName = item => item.updated_by?.name || ownerName(item);
const modifiedDate = item => item.updated_at ? new Date(item.updated_at).toLocaleString('es-MX') : 'Sin fecha';
const createdDate = item => item.created_at ? new Date(item.created_at).toLocaleString('es-MX') : 'Sin fecha';
</script>

<template>
    <Head title="Explorador" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><p class="text-sm text-slate-500 dark:text-slate-400">PlataformaDoc</p><h2 class="text-2xl font-semibold text-slate-900 dark:text-white">{{ showTrash ? 'Papelera de reciclaje' : currentFolderLabel }}</h2></div>
                <div class="flex gap-2"><button v-if="!showTrash" class="explorer-header-button relative" type="button" title="Detecta cambios (nuevos archivos, versiones o carpetas) y recarga el explorador" @click="refreshExplorer"><RefreshCw :size="15" class="align-text-bottom" /> Refrescar<span v-if="needsRefresh" class="absolute -right-1 -top-1 flex h-2.5 w-2.5"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span><span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-red-500"></span></span></button><button v-if="can('files.delete')" class="explorer-header-button" type="button" @click="toggleTrash">{{ showTrash ? 'Volver al explorador' : 'Papelera' }}</button></div>
            </div>
        </template>

        <div class="explorer-layout">
            <aside class="explorer-sidebar">
                <div class="explorer-sidebar-heading"><span class="explorer-sidebar-title"><Folder :size="14" />Ubicaciones</span><button v-if="can('folders.create')" type="button" title="Nueva carpeta" @click="showFolderModal = true">+</button></div>
                <button type="button" class="folder-tree-item" :class="{ 'folder-tree-item-active': !currentFolder && !showTrash }" @click="openFolder(null)"><span>⌂</span><span>Este equipo</span></button>
                <FolderTreeItem v-for="folder in folderTree" :key="folder.id" :folder="folder" :current-folder="currentFolder" @open="openFolder" />
                <div class="mt-auto border-t border-slate-200 pt-3"><button v-if="can('files.delete')" type="button" class="folder-tree-item" :class="{ 'folder-tree-item-active': showTrash }" @click="toggleTrash"><span>⌫</span><span>Papelera</span></button></div>
            </aside>

            <main class="explorer-main">
                <div v-if="$page.props.flash?.success" class="mb-4 rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:bg-emerald-950/70 dark:text-emerald-300">{{ $page.props.flash.success }}</div>
                <div class="explorer-toolbar">
                    <nav class="explorer-breadcrumbs" aria-label="Ruta"><button type="button" @click="openFolder(null)">Este equipo</button><template v-for="breadcrumb in breadcrumbs" :key="breadcrumb.id"><span>/</span><button type="button" @click="openFolder(breadcrumb.id)">{{ breadcrumb.name }}</button></template></nav>
                    <div v-if="!showTrash" class="flex gap-2"><button v-if="can('folders.create')" class="explorer-action-button" type="button" @click="showFolderModal = true"><FolderPlus :size="15" />Nueva carpeta</button><button v-if="can('docs.create')" class="explorer-action-button" type="button" @click="showDocumentModal = true"><FilePlus :size="15" />Nuevo documento</button></div><div v-else class="flex flex-wrap items-center gap-2"><span class="explorer-trash-note">Los elementos se eliminan definitivamente 7 días después de entrar a la papelera.</span><button v-if="can('files.delete') && items.length" class="explorer-action-button" type="button" @click="showEmptyTrash = true"><Trash2 :size="14" />Vaciar papelera</button></div>
                </div>
                <div v-if="!showTrash && can('files.upload')" class="explorer-dropzone" :class="{ 'explorer-dropzone-active': isDragging }" @dragover.prevent="isDragging = true" @dragleave.prevent="isDragging = false" @drop.prevent="handleDrop"><span>Arrastra archivos aquí o</span><label>selecciona uno<input type="file" class="hidden" accept=".pdf,.png,.jpg,.jpeg,.docx" @change="selectFile" /></label><button v-if="selectedFile && !previewMode" type="button" @click="uploadFile">{{ uploadForm.processing ? 'Subiendo...' : `Subir ${selectedFile.name}` }}</button></div>
                <section class="explorer-content-panel">
                    <div class="explorer-list-header"><span>Nombre</span><span>Tipo</span><span>Creado</span><span>Modificado</span><span class="explorer-actions-head">Acciones</span></div>
                    <div v-if="!visibleItems.length" class="explorer-empty"><Folder :size="32" class="mx-auto text-slate-300 dark:text-slate-600" /><p class="mt-3 text-sm font-medium text-slate-500 dark:text-slate-400">No hay elementos en esta carpeta.</p><p v-if="!showTrash" class="mt-1 text-xs text-slate-400 dark:text-slate-500">Usa «Nueva carpeta» o arrastra archivos para empezar.</p></div>
                    <div v-for="item in visibleItems" :key="`${item.kind}-${item.id}`" class="explorer-row" :class="{ 'explorer-row-active': item.kind === 'document' && selectedDocument && item.id === selectedDocument.id }" @dblclick="onRowDblClick(item)">
                        <button type="button" class="explorer-name-cell" @click="item.kind === 'folder' ? openFolder(item.id) : item.kind === 'file' ? previewFile(item) : previewDocument(item)">
                            <component :is="iconFor(item)" class="explorer-item-icon" :class="`explorer-item-icon-${item.kind}`" :size="17" stroke-width="1.8" />
                            <span class="min-w-0 truncate font-medium text-slate-800 dark:text-slate-100">{{ item.label }}</span>
                            <span v-if="item.kind === 'document'" class="lock-badge" :class="isLockedNow(item) ? 'lock-badge-locked' : 'lock-badge-free'">{{ lockLabel(item) }}</span>
                        </button>
                        <span class="explorer-type">{{ typeLabel(item) }}</span>
                        <span class="explorer-meta"><strong>{{ ownerName(item) }}</strong><small>{{ createdDate(item) }}</small></span>
                        <span class="explorer-meta"><strong>{{ modifierName(item) }}</strong><small>{{ modifiedDate(item) }}</small></span>
                        <div class="explorer-actions"><template v-if="showTrash && can('files.delete')">
                                <button type="button" @click="restore(`${item.kind}s`, item.id)"><RotateCcw :size="12" />Restaurar</button>
                                <button class="danger" type="button" @click="askForceDelete(item)"><Trash2 :size="12" />Eliminar</button>
                            </template><template v-else>
                                <button v-if="item.kind === 'file' && (isPdf(item) || isDocx(item)) && can('files.view')" type="button" @click="previewFile(item)"><Eye :size="12" />Previsualizar</button>
                                <button v-if="item.kind === 'document' && can('files.view')" type="button" @click="previewDocument(item)"><Eye :size="12" />Previsualizar</button>
                                <button v-if="item.kind === 'document' && can('files.view') && (item.linked_file || isPlatformDoc(item))" type="button" @click="openHistory(item)"><History :size="12" />Historial</button>
                                <button v-if="item.kind === 'document' && (item.linked_file || isPlatformDoc(item)) && canUploadVersions()" type="button" @click="openUploadVersion(item)"><Upload :size="12" />Subir versión</button>
                                <button v-if="item.kind === 'file' && isWordFile(item) && (item.document_id || can('docs.create')) && can('files.download')" type="button" :disabled="isLockedNow(item) && !modifierLockedByMe(item)" title="Descarga el archivo real (bloqueado) para editarlo en Word y sube los cambios aquí o desde el panel de Word" @click="startModifyFile(item)"><Pencil :size="12" />Modificar</button>
                                <button v-if="item.kind === 'document' && (item.linked_file || item.current_version_id || isPlatformDoc(item)) && can('files.download')" type="button" :disabled="isLockedNow(item) && !modifierLockedByMe(item)" title="Descarga el archivo real para editarlo en Word con fidelidad total" @click="startModify(item)"><Pencil :size="12" />Modificar</button>
                                <button v-if="isPlatformDoc(item) && canEditItem(item)" type="button" title="Abrir en el editor de la plataforma" @click="requestEdit(item)"><FileText :size="12" />Editar</button>
                                <a v-if="item.kind === 'file' && can('files.download')" :href="route('files.download', item.id)"><Download :size="12" />Descargar</a>
                                <button v-if="can('files.delete')" class="danger" type="button" @click="askRemove(item)"><Trash2 :size="12" />Eliminar</button>
                            </template></div>
                    </div>
                </section>
                <div v-if="pageCount > 1" class="explorer-pagination">
                    <button type="button" class="explorer-action-button" :disabled="page === 0" @click="page--"><ChevronLeft :size="14" />Anterior</button>
                    <span class="explorer-pagination-info">Página {{ page + 1 }} de {{ pageCount }} · {{ items.length }} {{ items.length === 1 ? 'elemento' : 'elementos' }}</span>
                    <button type="button" class="explorer-action-button" :disabled="page >= pageCount - 1" @click="page++">Siguiente<ChevronRight :size="14" /></button>
                </div>
            </main>
        </div>

        <div v-if="showFolderModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="showFolderModal = false">
            <form class="explorer-modal" @submit.prevent="createFolder">
                <div class="explorer-modal-icon"><Folder :size="20" /></div>
                <h3 class="explorer-modal-title">Nueva carpeta</h3>
                <p class="explorer-modal-subtitle">Se creará en: <strong>{{ currentFolderLabel }}</strong></p>
                <label class="explorer-modal-label" for="folder-name">Nombre de la carpeta</label>
                <input id="folder-name" v-model="folderForm.name" class="explorer-modal-input" placeholder="p. ej. Contratos 2026" required autofocus />
                <p v-if="folderForm.errors.name" class="explorer-modal-error">{{ folderForm.errors.name }}</p>
                <p v-if="folderForm.errors.parent_id" class="explorer-modal-error">{{ folderForm.errors.parent_id }}</p>
                <div class="explorer-modal-actions">
                    <button type="button" class="explorer-action-button" @click="showFolderModal = false">Cancelar</button>
                    <button type="submit" class="explorer-primary-button" :disabled="folderForm.processing">{{ folderForm.processing ? 'Creando...' : 'Crear carpeta' }}</button>
                </div>
            </form>
        </div>
        <div v-if="showDocumentModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="showDocumentModal = false">
            <form class="explorer-modal" @submit.prevent="createDocument">
                <div class="explorer-modal-icon"><FileText :size="20" /></div>
                <h3 class="explorer-modal-title">Nuevo documento</h3>
                <p class="explorer-modal-subtitle">Se creará en: <strong>{{ currentFolderLabel }}</strong> y se abrirá en el editor de la plataforma.</p>
                <label class="explorer-modal-label" for="document-title">Título del documento</label>
                <input id="document-title" v-model="docForm.title" class="explorer-modal-input" placeholder="p. ej. Acta de reunión" required autofocus />
                <p v-if="docForm.errors.title" class="explorer-modal-error">{{ docForm.errors.title }}</p>
                <p v-if="docForm.errors.folder_id" class="explorer-modal-error">{{ docForm.errors.folder_id }}</p>
                <div class="explorer-modal-actions">
                    <button type="button" class="explorer-action-button" @click="showDocumentModal = false">Cancelar</button>
                    <button type="submit" class="explorer-primary-button" :disabled="docForm.processing">{{ docForm.processing ? 'Creando...' : 'Crear y editar' }}</button>
                </div>
            </form>
        </div>
        <ConfirmModal :show="pendingDelete !== null" title="Enviar a la papelera" :message="`«${pendingDelete?.label}» se moverá a la papelera y se eliminará definitivamente en 7 días.`" confirm-label="Enviar a papelera" @confirm="confirmRemove" @cancel="pendingDelete = null" />
        <ConfirmModal :show="pendingForceDelete !== null" danger title="Eliminar definitivamente" :message="`«${pendingForceDelete?.label}» se eliminará para siempre y no se podrá recuperar.`" confirm-label="Eliminar para siempre" @confirm="confirmForceDelete" @cancel="pendingForceDelete = null" />
        <ConfirmModal :show="showEmptyTrash" danger title="Vaciar papelera" :message="`Se eliminarán definitivamente los ${items.length} elementos de la papelera. Esta acción no se puede deshacer.`" confirm-label="Vaciar papelera" @confirm="emptyTrash" @cancel="showEmptyTrash = false" />
        <div v-if="importWarningItem" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="importWarningItem = null">
            <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-slate-700 dark:bg-slate-800">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-sky-100 text-xl text-sky-600 dark:bg-sky-900/60 dark:text-sky-300">⚠️</div>
                    <div class="min-w-0">
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Documento importado desde Word</h3>
                        <p class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-300">
                            Al convertir <span class="font-medium text-slate-800 dark:text-slate-100">{{ importWarningItem.kind === 'document' ? importWarningItem.imported_from : importWarningItem.original_name }}</span>
                            y modificarlo por primera vez, el formato original (tablas, imágenes, estilos y saltos de página)
                            podría verse alterado. Te recomendamos revisar el contenido completo después de guardar los cambios.
                        </p>
                    </div>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="explorer-action-button" @click="importWarningItem = null">Cancelar</button>
                    <button type="button" class="explorer-primary-button" @click="proceedWithEdit">Convertir y editar</button>
                </div>
            </div>
        </div>
        <div v-if="uploadVersionItem" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="uploadVersionItem = null">
            <form class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-slate-700 dark:bg-slate-800" @submit.prevent="submitUploadVersion">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Subir nueva versión</h3>
                <p class="mt-1 text-sm leading-relaxed text-slate-500 dark:text-slate-400">El documento <span class="font-semibold text-slate-700 dark:text-slate-100">{{ uploadVersionItem.title }}</span> se actualizará con el archivo que subas (no importa si cambia de nombre) y su previsualización reflejará el nuevo contenido.</p>
                <label class="mt-4 flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed border-sky-200 bg-sky-50/60 p-3 text-sm font-medium text-sky-700 transition hover:bg-sky-100 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300 dark:hover:bg-sky-950">
                    <span>{{ uploadVersionFile ? uploadVersionFile.name : 'Selecciona un archivo .docx o .doc' }}</span>
                    <input type="file" class="hidden" accept=".doc,.docx" @change="onUploadVersionFile" />
                </label>
                <input v-model="uploadVersionNotes" class="mt-3 w-full rounded-md border-slate-300 bg-white text-slate-900 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100" placeholder="Notas de la nueva versión (opcional)" />
                <p v-if="uploadVersionError" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ uploadVersionError }}</p>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="explorer-action-button" @click="uploadVersionItem = null">Cancelar</button>
                    <button type="submit" class="explorer-primary-button" :disabled="uploadingVersion">{{ uploadingVersion ? 'Subiendo...' : 'Subir versión' }}</button>
                </div>
            </form>
        </div>
        <div v-if="modifyItem" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="cancelModify">
            <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-slate-700 dark:bg-slate-800">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Modificar en Word</h3>
                <p class="mt-1 text-sm leading-relaxed text-slate-500 dark:text-slate-400">Se descargó <span class="font-semibold text-slate-700 dark:text-slate-100">{{ modifyItem.title }}</span> con sus metadatos. Edita el archivo en Word con fidelidad total y vuelve a subir los cambios aquí, o guárdalos desde el panel de Word.</p>
                <div v-if="modifyLocked" class="mt-3 flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800 dark:bg-amber-950/50 dark:text-amber-300">
                    <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-amber-500"></span>
                    Bloqueado para ti hasta que guardes cambios o canceles.
                </div>
                <p v-if="modifyError" class="mt-2 rounded-md bg-red-50 px-3 py-2 text-sm text-red-600 dark:bg-red-950/50 dark:text-red-400">{{ modifyError }}</p>
                <template v-if="modifyLocked">
                    <label class="mt-4 flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed border-sky-200 bg-sky-50/60 p-3 text-sm font-medium text-sky-700 transition hover:bg-sky-100 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300 dark:hover:bg-sky-950">
                        <span>{{ modifyFile ? modifyFile.name : 'Selecciona el .docx editado en Word' }}</span>
                        <input type="file" class="hidden" accept=".doc,.docx" @change="onModifyFile" />
                    </label>
                    <input v-model="modifyNotes" class="mt-3 w-full rounded-md border-slate-300 bg-white text-slate-900 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100" placeholder="Notas del cambio (opcional)" />
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" class="explorer-action-button" :disabled="modifying" @click="cancelModify">Cancelar edición</button>
                        <button type="button" class="explorer-primary-button" :disabled="modifying || !modifyFile" @click="submitModify">{{ modifying ? 'Guardando...' : 'Guardar versión' }}</button>
                    </div>
                </template>
                <div v-else class="mt-5 flex justify-end gap-2">
                    <button type="button" class="explorer-action-button" :disabled="modifying" @click="closeModify">Cerrar</button>
                </div>
            </div>
        </div>
        <DocumentPreviewModal v-if="selectedDocument && previewingDocument" :document="selectedDocument" @close="closePreview" @upload="onPreviewUpload" @unlocked="onPreviewUnlocked" />
        <DocxPreviewModal v-if="selectedFile && previewMode === 'docx'" :file="selectedFile" :url="route('files.blob', selectedFile.id)" @close="closePreview" @edit="requestEdit(selectedFile)" />
        <PdfPreviewModal v-if="selectedFile && previewMode === 'pdf'" :file="selectedFile" :url="route('files.blob', selectedFile.id)" @close="closePreview" />
    </AuthenticatedLayout>
</template>

<style>
.explorer-layout { display: flex; min-height: calc(100vh - 8rem); background: var(--pd-bg-gradient); }
.explorer-sidebar { display: flex; width: 19rem; flex: 0 0 19rem; flex-direction: column; border-right: 1px solid var(--pd-border); background: color-mix(in srgb, var(--pd-surface) 88%, transparent); backdrop-filter: blur(8px); padding: 1.1rem 0.8rem; }
.explorer-sidebar-title { display: inline-flex; align-items: center; gap: 0.45rem; }
.explorer-sidebar-heading { display: flex; justify-content: space-between; padding: 0.25rem 0.65rem 0.75rem; color: var(--pd-text-4); font-size: 0.7rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
.explorer-sidebar-heading button { display: inline-flex; height: 1.5rem; width: 1.5rem; align-items: center; justify-content: center; border-radius: 9999px; background: var(--pd-accent-soft); color: var(--pd-accent-text); font-size: 1rem; line-height: 1; transition: background-color 120ms ease, transform 120ms ease; }
.explorer-sidebar-heading button:hover { transform: scale(1.08); }
.folder-tree-item { display: flex; width: 100%; align-items: center; gap: 0.55rem; border-radius: 0.7rem; padding: 0.55rem 0.8rem; color: var(--pd-text-2); font-size: 0.9rem; font-weight: 500; text-align: left; transition: background-color 120ms ease, color 120ms ease; }
.folder-tree-item:hover, .folder-tree-item-active { background: linear-gradient(90deg, var(--pd-accent-soft), var(--pd-surface-2)); color: var(--pd-accent-text); }
.folder-tree-icon { width: 0.8rem; color: var(--pd-text-4); font-size: 0.7rem; }
.explorer-main { min-width: 0; flex: 1; padding: 1.5rem; }
.explorer-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1.1rem; }
.explorer-breadcrumbs { display: flex; flex-wrap: wrap; align-items: center; gap: 0.45rem; color: var(--pd-text-3); font-size: 0.875rem; }
.explorer-breadcrumbs button:hover { color: var(--pd-text); }
.explorer-dropzone { display: flex; flex-wrap: wrap; align-items: center; margin-bottom: 1.1rem; border: 1.5px dashed var(--pd-accent); border-radius: 0.8rem; background: linear-gradient(120deg, var(--pd-accent-soft), var(--pd-surface)); padding: 0.85rem 1.1rem; color: var(--pd-text-3); font-size: 0.8rem; box-shadow: var(--pd-shadow-sm); transition: border-color 120ms ease, background 120ms ease; }
.explorer-dropzone-active { border-color: var(--pd-accent-text); background: var(--pd-accent-soft); }
.explorer-dropzone label { margin-left: 0.25rem; cursor: pointer; color: var(--pd-accent-text); font-weight: 700; }
.explorer-dropzone button { margin-left: 0.85rem; color: var(--pd-success-text); font-weight: 700; }
.explorer-content-panel { overflow: hidden; border: 1px solid var(--pd-border); border-radius: 0.9rem; background: var(--pd-surface); box-shadow: var(--pd-shadow-lg); }
.explorer-document-preview { margin-top: 1rem; border: 1px solid var(--pd-border); border-radius: 0.9rem; background: var(--pd-surface); padding: 1rem 1.25rem; box-shadow: var(--pd-shadow-lg); }
.explorer-preview-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; border-bottom: 1px solid var(--pd-border); padding-bottom: 0.75rem; }
.explorer-preview-heading button { color: var(--pd-text-4); font-size: 1.35rem; line-height: 1; transition: color 120ms ease; }
.explorer-preview-heading button:hover { color: var(--pd-danger-text); }
.explorer-preview-body { max-height: 18rem; overflow: auto; padding: 1rem 0.25rem; color: var(--pd-text-2); font-size: 0.9rem; line-height: 1.6; }
.explorer-preview-body h1, .explorer-preview-body h2, .explorer-preview-body h3 { margin: 0.7em 0 0.35em; color: var(--pd-text); }
.explorer-preview-body p { margin-bottom: 0.65em; }
.explorer-preview-body img { max-width: 100%; height: auto; }
.explorer-preview-body table { width: 100%; border-collapse: collapse; }
.explorer-preview-body td, .explorer-preview-body th { border: 1px solid var(--pd-border-strong); padding: 0.35rem; }
.explorer-list-header, .explorer-row { display: grid; grid-template-columns: minmax(12rem, 2fr) minmax(7rem, 0.8fr) minmax(8.5rem, 1fr) minmax(8.5rem, 1fr) minmax(10rem, 1.1fr); align-items: center; gap: 0.9rem; padding: 0.7rem 1.1rem; }
.explorer-list-header { border-bottom: 1px solid var(--pd-border); background: linear-gradient(180deg, var(--pd-surface-2), var(--pd-surface-3)); color: var(--pd-text-4); font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; }
.explorer-row { min-height: 3.8rem; border-bottom: 1px solid var(--pd-border); font-size: 0.8rem; transition: background-color 120ms ease; }
.explorer-row:last-child { border-bottom: 0; }
.explorer-row:hover { background: var(--pd-surface-2); }
.explorer-row-active { background: var(--pd-accent-soft); box-shadow: inset 3px 0 0 var(--pd-accent); }
.explorer-row-active:hover { background: var(--pd-accent-soft); }
.explorer-name-cell { display: flex; min-width: 0; align-items: center; gap: 0.6rem; text-align: left; }
.explorer-name-cell .lock-badge { margin-left: -0.3rem; }
.explorer-actions-head { text-align: right; }
.explorer-actions button, .explorer-actions a { white-space: nowrap; }
.explorer-empty { padding: 3.5rem 1rem; text-align: center; }
.explorer-content-panel button:focus-visible, .explorer-content-panel a:focus-visible, .explorer-header-button:focus-visible, .explorer-action-button:focus-visible, .explorer-primary-button:focus-visible, .folder-tree-item:focus-visible, .explorer-preview-nav-button:focus-visible, .explorer-dropzone label:focus-visible { outline: 2px solid var(--pd-accent); outline-offset: 2px; }
.explorer-item-icon { display: flex; width: 2.1rem; height: 2.1rem; flex: 0 0 2.1rem; align-items: center; justify-content: center; border-radius: 0.6rem; transition: transform 120ms ease; }
.explorer-item-icon-folder { background: var(--pd-warn-soft); color: var(--pd-warn-text); }
.explorer-item-icon-document { background: var(--pd-accent-soft); color: var(--pd-accent-text); }
.explorer-item-icon-file { background: var(--pd-surface-3); color: var(--pd-text-4); }
.explorer-name-cell:hover .explorer-item-icon { transform: scale(1.08); }
.explorer-type { display: inline-flex; align-items: center; width: fit-content; max-width: 100%; border-radius: 9999px; background: var(--pd-surface-3); padding: 0.2rem 0.6rem; color: var(--pd-text-3); font-size: 0.68rem; font-weight: 600; white-space: nowrap; }
.explorer-meta { display: flex; min-width: 0; flex-direction: column; color: var(--pd-text-2); line-height: 1.35; }
.explorer-meta strong { font-size: 0.78rem; font-weight: 600; }
.explorer-meta small { overflow: hidden; color: var(--pd-text-4); font-size: 0.7rem; text-overflow: ellipsis; white-space: nowrap; }
.explorer-actions { display: flex; flex-wrap: wrap; gap: 0.4rem; justify-content: flex-end; }
.explorer-actions button, .explorer-actions a { display: inline-flex; align-items: center; gap: 0.35rem; border: 1px solid var(--pd-border); border-radius: 9999px; background: var(--pd-surface); padding: 0.3rem 0.65rem; color: var(--pd-text-3); font-size: 0.72rem; font-weight: 600; text-decoration: none; transition: border-color 120ms ease, background-color 120ms ease, color 120ms ease, box-shadow 120ms ease, transform 120ms ease; }
.explorer-actions button:hover, .explorer-actions a:hover { border-color: var(--pd-accent); background: var(--pd-accent-soft); color: var(--pd-accent-text); box-shadow: 0 3px 10px rgb(14 165 233 / 14%); transform: translateY(-1px); }
.explorer-actions .danger { color: var(--pd-danger-text); border-color: var(--pd-danger-border); background: var(--pd-surface); }
.explorer-actions .danger:hover { background: var(--pd-danger-hover); border-color: var(--pd-danger-border); color: var(--pd-danger-text); }
.lock-badge { display: inline-flex; align-items: center; border-radius: 9999px; padding: 0.16rem 0.5rem; font-size: 0.62rem; font-weight: 700; letter-spacing: 0.02em; white-space: nowrap; }
.lock-badge-free { background: var(--pd-success-soft); color: var(--pd-success-text); }
.lock-badge-locked { background: var(--pd-warn-soft); color: var(--pd-warn-text); }
.explorer-preview-footer { display: flex; flex-wrap: wrap; align-items: center; gap: 0.6rem; border-top: 1px solid var(--pd-border); padding-top: 0.75rem; }
.explorer-pagination { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 0.75rem; margin-top: 0.9rem; }
.explorer-pagination-info { color: var(--pd-text-3); font-size: 0.78rem; }
.explorer-pagination .explorer-action-button:disabled { cursor: not-allowed; opacity: 0.42; transform: none; box-shadow: none; }
.explorer-preview-nav { display: flex; align-items: center; gap: 0.75rem; border-bottom: 1px solid var(--pd-border); padding: 0.6rem 0; }
.explorer-preview-nav-button { border: 1px solid var(--pd-border); border-radius: 9999px; background: var(--pd-surface); padding: 0.32rem 0.75rem; font-size: 0.75rem; font-weight: 600; color: var(--pd-text-2); box-shadow: var(--pd-shadow-sm); transition: border-color 120ms ease, color 120ms ease; }
.explorer-preview-nav-button:hover:not(:disabled) { border-color: var(--pd-accent); color: var(--pd-accent-text); }
.explorer-preview-nav-button:disabled { cursor: not-allowed; opacity: 0.4; }
.explorer-preview-position { min-width: 3.2rem; text-align: center; color: var(--pd-text-4); font-size: 0.75rem; font-weight: 600; }
@media (max-width: 1250px) { .explorer-sidebar { width: 17rem; flex-basis: 17rem; } .explorer-list-header, .explorer-row { grid-template-columns: minmax(11rem, 2fr) minmax(7rem, 0.8fr) minmax(8.5rem, 1fr) minmax(10rem, 1.1fr); } .explorer-list-header span:nth-child(4), .explorer-row > span:nth-child(4) { display: none; } }
@media (max-width: 900px) { .explorer-sidebar { width: 14rem; flex-basis: 14rem; } .explorer-list-header, .explorer-row { grid-template-columns: minmax(0, 1fr) auto; } .explorer-list-header span:nth-child(2), .explorer-row > span:nth-child(2), .explorer-list-header span:nth-child(4), .explorer-row > span:nth-child(4) { display: none; } .explorer-row > .explorer-actions { grid-column: 1 / -1; justify-content: flex-start; } }
@media (max-width: 640px) {
    .explorer-layout { display: block; }
    .explorer-sidebar { width: 100%; min-height: auto; max-height: 16rem; overflow-y: auto; border-right: 0; border-bottom: 1px solid var(--pd-border); }
    .explorer-sidebar > .folder-tree-item:nth-of-type(n+7) { display: none; }
    .explorer-main { padding: 0.75rem; }
    .explorer-toolbar > .flex { flex-wrap: wrap; }
    .explorer-list-header { display: none; }
    .explorer-row { grid-template-columns: minmax(0, 1fr); gap: 0.5rem; padding: 0.75rem; }
    .explorer-name-cell { flex-wrap: wrap; }
    .explorer-name-cell .lock-badge { margin-left: 0; }
    .explorer-actions { flex-direction: row; flex-wrap: wrap; justify-content: flex-start; }
    .explorer-actions button, .explorer-actions a { max-width: 100%; }
}
.explorer-trash-note { color: var(--pd-text-4); font-size: 0.75rem; }
.explorer-modal { width: 100%; max-width: 28rem; border: 1px solid var(--pd-border); border-radius: 1rem; background: var(--pd-surface); padding: 1.5rem; box-shadow: var(--pd-shadow-lg); }
.explorer-modal-icon { display: flex; height: 2.75rem; width: 2.75rem; align-items: center; justify-content: center; border-radius: 0.8rem; background: var(--pd-accent-soft); color: var(--pd-accent-text); }
.explorer-modal-title { margin-top: 0.9rem; color: var(--pd-text); font-size: 1.15rem; font-weight: 700; }
.explorer-modal-subtitle { margin-top: 0.25rem; color: var(--pd-text-3); font-size: 0.82rem; }
.explorer-modal-subtitle strong { color: var(--pd-text-2); }
.explorer-modal-label { display: block; margin-top: 1.1rem; margin-bottom: 0.35rem; color: var(--pd-text-2); font-size: 0.78rem; font-weight: 600; }
.explorer-modal-input { width: 100%; border: 1px solid var(--pd-border); border-radius: 0.55rem; background: var(--pd-surface); padding: 0.55rem 0.75rem; color: var(--pd-text); font-size: 0.9rem; }
.explorer-modal-input:focus { border-color: var(--pd-accent); outline: 2px solid var(--pd-accent); outline-offset: 1px; }
.explorer-modal-error { margin-top: 0.4rem; color: var(--pd-danger-text); font-size: 0.78rem; }
.explorer-modal-actions { display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 1.4rem; }
</style>
