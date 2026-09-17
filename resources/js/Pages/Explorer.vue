<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { RefreshCw, Folder, FolderPlus, FolderOpen, FileText, File, FilePlus, Image, Eye, History, Upload, Download, Trash2, RotateCcw, Pencil, ChevronLeft, ChevronRight, ChevronDown, Home, Search, LayoutGrid, List, HardDrive, Sparkles } from 'lucide-vue-next';
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

// Vista, búsqueda y orden (solo cliente, no tocan el servidor).
const view = ref(typeof localStorage !== 'undefined' && localStorage.getItem('pd_explorer_view') === 'grid' ? 'grid' : 'list');
const setView = value => {
    view.value = value;
    try { localStorage.setItem('pd_explorer_view', value); } catch (e) { /* ignorar */ }
};
const search = ref('');
const sortBy = ref('name'); // name | type | modified | size
const sortOptions = [['name', 'Nombre'], ['type', 'Tipo'], ['modified', 'Modificado'], ['size', 'Tamaño']];

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

// Buscador + filtro por tipo + orden (cliente).
const kindFilter = ref('all'); // all | folder | document | file
const toggleKind = kind => { kindFilter.value = kindFilter.value === kind ? 'all' : kind; page.value = 0; };
const filteredItems = computed(() => {
    const q = search.value.trim().toLowerCase();
    return items.value.filter(item => {
        if (kindFilter.value !== 'all' && item.kind !== kindFilter.value) return false;
        if (!q) return true;
        return `${item.label} ${typeLabel(item)} ${ownerName(item)}`.toLowerCase().includes(q);
    });
});
const sizeOf = item => item.kind === 'folder' ? -1 : Number(item.file_size || 0);
const sortedItems = computed(() => {
    const arr = [...filteredItems.value];
    switch (sortBy.value) {
        case 'type': return arr.sort((a, b) => a.sort - b.sort || typeLabel(a).localeCompare(typeLabel(b)) || a.label.localeCompare(b.label));
        case 'modified': return arr.sort((a, b) => new Date(b.updated_at || 0) - new Date(a.updated_at || 0));
        case 'size': return arr.sort((a, b) => sizeOf(b) - sizeOf(a) || a.label.localeCompare(b.label));
        default: return arr.sort((a, b) => a.sort - b.sort || a.label.localeCompare(b.label));
    }
});
const counts = computed(() => ({ folders: props.folders.length, documents: props.documents.length, files: props.files.length, total: items.value.length }));

// Insignia de extensión por tipo de archivo.
const extOf = item => {
    if (item.kind === 'folder') return '';
    const name = item.original_name || item.linked_file?.original_name || item.label || '';
    return (name.split('.').pop() || '').toLowerCase();
};
const extBadge = item => {
    if (item.kind === 'folder') return 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300';
    if (item.kind === 'document') return 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300';
    const ext = extOf(item);
    if (ext === 'pdf') return 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300';
    if (['doc', 'docx'].includes(ext)) return 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300';
    if (['xls', 'xlsx', 'csv'].includes(ext)) return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300';
    if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp'].includes(ext)) return 'bg-purple-100 text-purple-700 dark:bg-purple-950 dark:text-purple-300';
    if (['zip', 'rar', '7z'].includes(ext)) return 'bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300';
    return 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300';
};
const tileClass = item => {
    if (item.kind === 'folder') return 'bg-gradient-to-br from-amber-400 to-orange-500 text-white shadow-amber-500/30';
    if (item.kind === 'document') return 'bg-gradient-to-br from-sky-500 to-indigo-600 text-white shadow-sky-500/30';
    const ext = extOf(item);
    if (ext === 'pdf') return 'bg-gradient-to-br from-red-500 to-rose-600 text-white shadow-red-500/30';
    if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp'].includes(ext)) return 'bg-gradient-to-br from-violet-500 to-purple-600 text-white shadow-violet-500/30';
    return 'bg-gradient-to-br from-slate-400 to-slate-600 text-white shadow-slate-500/30';
};

const pageSize = 15;
const page = ref(0);
const pageCount = computed(() => Math.max(1, Math.ceil(sortedItems.value.length / pageSize)));
const visibleItems = computed(() => sortedItems.value.slice(page.value * pageSize, (page.value + 1) * pageSize));
watch([() => props.currentFolder, () => props.showTrash], () => { page.value = 0; });
watch([search, sortBy], () => { page.value = 0; });
watch(() => sortedItems.value.length, () => {
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
const openItem = item => {
    if (item.kind === 'folder') return openFolder(item.id);
    if (item.kind === 'file') {
        if (isWordFile(item)) return startModifyFile(item);
        return previewFile(item);
    }
    return previewDocument(item);
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
                <div class="flex items-center gap-3">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl text-white shadow-lg" :class="showTrash ? 'bg-gradient-to-br from-red-500 to-rose-600 shadow-red-500/30' : 'bg-gradient-to-br from-amber-400 to-orange-500 shadow-amber-500/30'">
                        <Trash2 v-if="showTrash" :size="22" /><FolderOpen v-else :size="22" />
                    </span>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">PlataformaDoc · {{ counts.total }} elementos</p>
                        <h2 class="text-2xl font-extrabold text-slate-900 dark:text-white">{{ showTrash ? 'Papelera de reciclaje' : currentFolderLabel }}</h2>
                    </div>
                </div>
                <div class="flex gap-2">
                    <button class="explorer-header-button relative" type="button" title="Detecta cambios (nuevos archivos, versiones o carpetas) y recarga el explorador" @click="refreshExplorer"><RefreshCw :size="15" class="align-text-bottom" /> Refrescar<span v-if="needsRefresh" class="absolute -right-1 -top-1 flex h-2.5 w-2.5"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span><span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-red-500"></span></span></button>
                    <button v-if="can('files.delete')" class="explorer-header-button" type="button" @click="toggleTrash">{{ showTrash ? 'Volver al explorador' : 'Papelera' }}</button>
                </div>
            </div>
        </template>

        <div class="explorer-layout">
            <aside class="explorer-sidebar">
                <div class="explorer-sidebar-heading"><span class="explorer-sidebar-title"><Folder :size="14" />Ubicaciones</span><button v-if="can('folders.create')" type="button" title="Nueva carpeta" @click="showFolderModal = true"><FolderPlus :size="13" /></button></div>
                <button type="button" class="folder-tree-item" :class="{ 'folder-tree-item-active': !currentFolder && !showTrash }" @click="openFolder(null)"><Home :size="15" class="shrink-0" /><span class="flex-1 truncate">Este equipo</span><span class="folder-tree-count">{{ counts.total }}</span></button>
                <FolderTreeItem v-for="folder in folderTree" :key="folder.id" :folder="folder" :current-folder="currentFolder" @open="openFolder" />
                <div class="mt-auto border-t border-slate-200/70 pt-3 dark:border-slate-700/70"><button v-if="can('files.delete')" type="button" class="folder-tree-item" :class="{ 'folder-tree-item-active': showTrash }" @click="toggleTrash"><Trash2 :size="15" class="shrink-0" /><span class="flex-1 truncate">Papelera</span><span class="text-[0.65rem] text-slate-400">7 días</span></button></div>
            </aside>

            <main class="explorer-main">
                <div v-if="$page.props.flash?.success" class="mb-4 flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/70 dark:text-emerald-300"><Sparkles :size="16" />{{ $page.props.flash.success }}</div>

                <!-- Tarjetas resumen -->
                <div class="mb-4 grid grid-cols-2 gap-3 xl:grid-cols-4">
                    <button type="button" class="stat-card" :class="{ 'stat-active': kindFilter === 'folder' }" title="Filtrar carpetas" @click="toggleKind('folder')">
                        <span class="stat-icon bg-gradient-to-br from-amber-400 to-orange-500 text-white shadow-amber-500/30"><Folder :size="18" /></span>
                        <span><span class="stat-num">{{ counts.folders }}</span><span class="stat-label">Carpetas</span></span>
                    </button>
                    <button type="button" class="stat-card" :class="{ 'stat-active': kindFilter === 'document' }" title="Filtrar documentos" @click="toggleKind('document')">
                        <span class="stat-icon bg-gradient-to-br from-sky-500 to-indigo-600 text-white shadow-sky-500/30"><FileText :size="18" /></span>
                        <span><span class="stat-num">{{ counts.documents }}</span><span class="stat-label">Documentos</span></span>
                    </button>
                    <button type="button" class="stat-card" :class="{ 'stat-active': kindFilter === 'file' }" title="Filtrar archivos" @click="toggleKind('file')">
                        <span class="stat-icon bg-gradient-to-br from-violet-500 to-purple-600 text-white shadow-violet-500/30"><File :size="18" /></span>
                        <span><span class="stat-num">{{ counts.files }}</span><span class="stat-label">Archivos</span></span>
                    </button>
                    <button type="button" class="stat-card" title="Quitar filtros" @click="kindFilter = 'all'; search = ''; page = 0">
                        <span class="stat-icon bg-gradient-to-br from-emerald-500 to-teal-600 text-white shadow-emerald-500/30"><HardDrive :size="18" /></span>
                        <span><span class="stat-num">{{ sortedItems.length }}</span><span class="stat-label">En vista · limpiar</span></span>
                    </button>
                </div>

                <div class="explorer-toolbar">
                    <nav class="explorer-breadcrumbs" aria-label="Ruta">
                        <button type="button" class="crumb" :class="{ 'crumb-active': !currentFolder }" @click="openFolder(null)"><Home :size="13" />Este equipo</button>
                        <template v-for="breadcrumb in breadcrumbs" :key="breadcrumb.id"><ChevronRight :size="13" class="shrink-0 text-slate-300 dark:text-slate-600" /><button type="button" class="crumb" @click="openFolder(breadcrumb.id)">{{ breadcrumb.name }}</button></template>
                    </nav>
                    <div v-if="!showTrash" class="flex flex-wrap gap-2"><button v-if="can('folders.create')" class="explorer-action-button" type="button" @click="showFolderModal = true"><FolderPlus :size="15" />Nueva carpeta</button><button v-if="can('docs.create')" class="explorer-primary-button" type="button" @click="showDocumentModal = true"><FilePlus :size="15" />Nuevo documento</button></div><div v-else class="flex flex-wrap items-center gap-2"><span class="explorer-trash-note">Los elementos se eliminan definitivamente 7 días después de entrar a la papelera.</span><button v-if="can('files.delete') && items.length" class="explorer-action-button" type="button" @click="showEmptyTrash = true"><Trash2 :size="14" />Vaciar papelera</button></div>
                </div>

                <!-- Buscador + orden + vista -->
                <div class="mb-3 flex flex-col gap-2 rounded-2xl border border-slate-200 bg-white p-2.5 shadow-sm sm:flex-row sm:items-center dark:border-slate-700 dark:bg-slate-800">
                    <label class="relative flex-1">
                        <Search :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                        <input v-model="search" type="search" placeholder="Buscar en esta carpeta…" class="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pl-9 pr-3 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-sky-400 focus:bg-white focus:ring-2 focus:ring-sky-100 dark:border-slate-600 dark:bg-slate-700/60 dark:text-slate-100 dark:focus:border-sky-600 dark:focus:ring-sky-950" />
                    </label>
                    <div class="flex items-center gap-2">
                        <label class="flex items-center gap-1.5 text-xs font-semibold text-slate-400">
                            <ChevronDown :size="13" />
                            <select v-model="sortBy" class="cursor-pointer rounded-xl border border-slate-200 bg-slate-50 px-2 py-2 text-xs font-bold text-slate-600 outline-none dark:border-slate-600 dark:bg-slate-700/60 dark:text-slate-300">
                                <option v-for="[value, label] in sortOptions" :key="value" :value="value">{{ label }}</option>
                            </select>
                        </label>
                        <div class="flex rounded-xl bg-slate-100 p-1 dark:bg-slate-700">
                            <button type="button" title="Vista lista" class="rounded-lg p-1.5 transition" :class="view === 'list' ? 'bg-white text-sky-600 shadow dark:bg-slate-800 dark:text-sky-400' : 'text-slate-400 hover:text-slate-600'" @click="setView('list')"><List :size="15" /></button>
                            <button type="button" title="Vista cuadrícula" class="rounded-lg p-1.5 transition" :class="view === 'grid' ? 'bg-white text-sky-600 shadow dark:bg-slate-800 dark:text-sky-400' : 'text-slate-400 hover:text-slate-600'" @click="setView('grid')"><LayoutGrid :size="15" /></button>
                        </div>
                    </div>
                </div>

                <div v-if="!showTrash && can('files.upload')" class="explorer-dropzone" :class="{ 'explorer-dropzone-active': isDragging }" @dragover.prevent="isDragging = true" @dragleave.prevent="isDragging = false" @drop.prevent="handleDrop">
                    <span class="drop-icon"><Upload :size="17" /></span>
                    <span>Arrastra archivos aquí o</span><label>selecciona uno<input type="file" class="hidden" accept=".pdf,.png,.jpg,.jpeg,.docx" @change="selectFile" /></label>
                    <button v-if="selectedFile && !previewMode" type="button" @click="uploadFile">{{ uploadForm.processing ? 'Subiendo…' : `Subir ${selectedFile.name}` }}</button>
                    <span v-else-if="selectedFile" class="drop-chosen">{{ selectedFile.name }}</span>
                </div>

                <!-- Vista lista -->
                <section v-if="view === 'list'" class="explorer-content-panel">
                    <div class="explorer-list-header"><span>Nombre</span><span>Tipo</span><span>Creado</span><span>Modificado</span><span class="explorer-actions-head">Acciones</span></div>
                    <div v-if="!visibleItems.length" class="explorer-empty"><span class="empty-illo"><FolderOpen :size="30" /></span><p class="mt-3 text-sm font-bold text-slate-600 dark:text-slate-300">{{ search || kindFilter !== 'all' ? 'Sin resultados con esos filtros' : 'Carpeta vacía' }}</p><p v-if="!showTrash" class="mt-1 text-xs text-slate-400 dark:text-slate-500">Usa «Nueva carpeta» o arrastra archivos para empezar. ✨</p><button v-if="search || kindFilter !== 'all'" type="button" class="explorer-action-button mx-auto mt-3" @click="search = ''; kindFilter = 'all'">Limpiar filtros</button></div>
                    <div v-for="(item, index) in visibleItems" :key="`${item.kind}-${item.id}`" class="explorer-row animate-item" :style="{ animationDelay: `${Math.min(index * 25, 300)}ms` }" :class="{ 'explorer-row-active': item.kind === 'document' && selectedDocument && item.id === selectedDocument.id }" @dblclick="onRowDblClick(item)">
                        <button type="button" class="explorer-name-cell" @click="item.kind === 'folder' ? openFolder(item.id) : item.kind === 'file' ? previewFile(item) : previewDocument(item)">
                            <span class="explorer-item-icon" :class="tileClass(item)"><component :is="iconFor(item)" :size="17" stroke-width="1.8" /></span>
                            <span class="min-w-0"><span class="block truncate font-semibold text-slate-800 dark:text-slate-100">{{ item.label }}</span><span v-if="item.kind !== 'folder'" class="mt-0.5 inline-block rounded-full px-1.5 py-px text-[0.62rem] font-bold uppercase tracking-wide" :class="extBadge(item)">{{ item.kind === 'document' ? (item.linked_file ? (item.linked_file.original_name?.split('.').pop() || 'docx') : 'doc') : extOf(item) }}</span></span>
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

                <!-- Vista cuadrícula -->
                <section v-else>
                    <div v-if="!visibleItems.length" class="explorer-empty rounded-2xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800"><span class="empty-illo"><FolderOpen :size="30" /></span><p class="mt-3 text-sm font-bold text-slate-600 dark:text-slate-300">{{ search || kindFilter !== 'all' ? 'Sin resultados con esos filtros' : 'Carpeta vacía' }}</p><p v-if="!showTrash" class="mt-1 text-xs text-slate-400 dark:text-slate-500">Usa «Nueva carpeta» o arrastra archivos para empezar. ✨</p><button v-if="search || kindFilter !== 'all'" type="button" class="explorer-action-button mx-auto mt-3" @click="search = ''; kindFilter = 'all'">Limpiar filtros</button></div>
                    <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                        <div v-for="(item, index) in visibleItems" :key="`${item.kind}-${item.id}`" class="grid-card animate-item group" :style="{ animationDelay: `${Math.min(index * 25, 300)}ms` }" :class="{ 'grid-card-active': item.kind === 'document' && selectedDocument && item.id === selectedDocument.id }" @click="openItem(item)" @dblclick="onRowDblClick(item)">
                            <div class="flex items-start justify-between gap-2">
                                <span class="grid-tile" :class="tileClass(item)"><component :is="iconFor(item)" :size="22" stroke-width="1.7" /></span>
                                <span v-if="item.kind === 'document'" class="lock-badge" :class="isLockedNow(item) ? 'lock-badge-locked' : 'lock-badge-free'">{{ isLockedNow(item) ? '🔒' : '✓' }}</span>
                                <button v-if="can('files.delete')" type="button" title="Eliminar" class="grid-del" @click.stop="showTrash ? askForceDelete(item) : askRemove(item)"><Trash2 :size="13" /></button>
                            </div>
                            <p class="mt-2.5 truncate text-sm font-bold text-slate-800 dark:text-slate-100" :title="item.label">{{ item.label }}</p>
                            <p class="mt-0.5 flex items-center gap-1.5 text-[0.7rem] text-slate-400">
                                <span v-if="item.kind !== 'folder'" class="rounded-full px-1.5 py-px font-bold uppercase tracking-wide" :class="extBadge(item)">{{ item.kind === 'document' ? 'doc' : extOf(item) }}</span>
                                <span class="truncate">{{ typeLabel(item) }}</span>
                            </p>
                            <p class="mt-1 truncate text-[0.7rem] text-slate-400">{{ item.kind === 'folder' ? 'Carpeta' : modifierName(item) }} · {{ modifiedDate(item) }}</p>
                            <div class="mt-2 flex flex-wrap gap-1 border-t border-slate-100 pt-2 dark:border-slate-700" @click.stop>
                                <template v-if="showTrash && can('files.delete')">
                                    <button type="button" class="mini-btn" title="Restaurar" @click="restore(`${item.kind}s`, item.id)"><RotateCcw :size="13" /></button>
                                </template>
                                <template v-else>
                                    <button v-if="item.kind === 'folder'" type="button" class="mini-btn" title="Abrir" @click="openFolder(item.id)"><FolderOpen :size="13" /></button>
                                    <button v-if="item.kind === 'file' && (isPdf(item) || isDocx(item)) && can('files.view')" type="button" class="mini-btn" title="Previsualizar" @click="previewFile(item)"><Eye :size="13" /></button>
                                    <button v-if="item.kind === 'document' && can('files.view')" type="button" class="mini-btn" title="Previsualizar" @click="previewDocument(item)"><Eye :size="13" /></button>
                                    <button v-if="item.kind === 'document' && can('files.view') && (item.linked_file || isPlatformDoc(item))" type="button" class="mini-btn" title="Historial" @click="openHistory(item)"><History :size="13" /></button>
                                    <button v-if="item.kind === 'document' && (item.linked_file || isPlatformDoc(item)) && canUploadVersions()" type="button" class="mini-btn" title="Subir versión" @click="openUploadVersion(item)"><Upload :size="13" /></button>
                                    <button v-if="item.kind === 'file' && isWordFile(item) && (item.document_id || can('docs.create')) && can('files.download')" type="button" class="mini-btn" title="Modificar en Word" :disabled="isLockedNow(item) && !modifierLockedByMe(item)" @click="startModifyFile(item)"><Pencil :size="13" /></button>
                                    <button v-if="item.kind === 'document' && (item.linked_file || item.current_version_id || isPlatformDoc(item)) && can('files.download')" type="button" class="mini-btn" title="Modificar en Word" :disabled="isLockedNow(item) && !modifierLockedByMe(item)" @click="startModify(item)"><Pencil :size="13" /></button>
                                    <button v-if="isPlatformDoc(item) && canEditItem(item)" type="button" class="mini-btn" title="Editar" @click="requestEdit(item)"><FileText :size="13" /></button>
                                    <a v-if="item.kind === 'file' && can('files.download')" class="mini-btn" title="Descargar" :href="route('files.download', item.id)"><Download :size="13" /></a>
                                </template>
                            </div>
                        </div>
                    </div>
                </section>

                <div v-if="pageCount > 1" class="explorer-pagination">
                    <button type="button" class="explorer-action-button" :disabled="page === 0" @click="page--"><ChevronLeft :size="14" />Anterior</button>
                    <span class="explorer-pagination-info">Página {{ page + 1 }} de {{ pageCount }} · {{ sortedItems.length }} de {{ items.length }} elementos</span>
                    <button type="button" class="explorer-action-button" :disabled="page >= pageCount - 1" @click="page++">Siguiente<ChevronRight :size="14" /></button>
                </div>
                <p v-else class="mt-3 text-right text-xs text-slate-400">{{ sortedItems.length }} de {{ items.length }} elementos</p>
            </main>
        </div>

        <div v-if="showFolderModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4 backdrop-blur-sm" @click.self="showFolderModal = false">
            <form class="w-full max-w-md overflow-hidden rounded-3xl bg-white shadow-2xl dark:bg-slate-900" @submit.prevent="createFolder">
                <div class="relative bg-gradient-to-r from-amber-500 to-orange-500 px-5 pb-5 pt-5 text-white">
                    <Folder :size="52" class="absolute -right-4 -top-4 opacity-30" />
                    <div class="relative flex items-center gap-3">
                        <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-white/20 backdrop-blur"><FolderPlus :size="20" /></span>
                        <div><h3 class="text-lg font-extrabold leading-tight">Nueva carpeta</h3><p class="text-xs text-amber-100">Se creará en: <strong>{{ currentFolderLabel }}</strong></p></div>
                    </div>
                </div>
                <div class="px-5 py-4">
                    <label class="mb-1 block text-xs font-extrabold uppercase tracking-wide text-slate-500 dark:text-slate-400" for="folder-name">📁 Nombre de la carpeta</label>
                    <input id="folder-name" v-model="folderForm.name" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-800 outline-none transition placeholder:font-normal placeholder:text-slate-400 focus:border-amber-400 focus:bg-white focus:ring-4 focus:ring-amber-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:focus:ring-amber-950" placeholder="p. ej. Contratos 2026" required autofocus />
                    <p v-if="folderForm.errors.name" class="mt-1 text-xs font-semibold text-red-500">{{ folderForm.errors.name }}</p>
                    <p v-if="folderForm.errors.parent_id" class="mt-1 text-xs font-semibold text-red-500">{{ folderForm.errors.parent_id }}</p>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-100 bg-slate-50/70 px-5 py-3.5 dark:border-slate-700 dark:bg-slate-800/60">
                    <button type="button" class="rounded-xl px-3.5 py-2 text-xs font-bold text-slate-500 transition hover:bg-slate-200/70 dark:text-slate-300 dark:hover:bg-slate-700" @click="showFolderModal = false">Cancelar</button>
                    <button type="submit" class="flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 px-4 py-2 text-xs font-extrabold text-white shadow-lg shadow-amber-500/30 transition hover:brightness-110 disabled:opacity-60" :disabled="folderForm.processing">{{ folderForm.processing ? 'Creando…' : 'Crear carpeta' }}</button>
                </div>
            </form>
        </div>
        <div v-if="showDocumentModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4 backdrop-blur-sm" @click.self="showDocumentModal = false">
            <form class="w-full max-w-md overflow-hidden rounded-3xl bg-white shadow-2xl dark:bg-slate-900" @submit.prevent="createDocument">
                <div class="relative bg-gradient-to-r from-sky-600 to-indigo-600 px-5 pb-5 pt-5 text-white">
                    <FileText :size="52" class="absolute -right-4 -top-4 opacity-30" />
                    <div class="relative flex items-center gap-3">
                        <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-white/20 backdrop-blur"><FilePlus :size="20" /></span>
                        <div><h3 class="text-lg font-extrabold leading-tight">Nuevo documento</h3><p class="text-xs text-sky-100">En <strong>{{ currentFolderLabel }}</strong> · se abre en el editor ✍️</p></div>
                    </div>
                </div>
                <div class="px-5 py-4">
                    <label class="mb-1 block text-xs font-extrabold uppercase tracking-wide text-slate-500 dark:text-slate-400" for="document-title">📄 Título del documento</label>
                    <input id="document-title" v-model="docForm.title" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-800 outline-none transition placeholder:font-normal placeholder:text-slate-400 focus:border-sky-400 focus:bg-white focus:ring-4 focus:ring-sky-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:focus:ring-sky-950" placeholder="p. ej. Acta de reunión" required autofocus />
                    <p v-if="docForm.errors.title" class="mt-1 text-xs font-semibold text-red-500">{{ docForm.errors.title }}</p>
                    <p v-if="docForm.errors.folder_id" class="mt-1 text-xs font-semibold text-red-500">{{ docForm.errors.folder_id }}</p>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-100 bg-slate-50/70 px-5 py-3.5 dark:border-slate-700 dark:bg-slate-800/60">
                    <button type="button" class="rounded-xl px-3.5 py-2 text-xs font-bold text-slate-500 transition hover:bg-slate-200/70 dark:text-slate-300 dark:hover:bg-slate-700" @click="showDocumentModal = false">Cancelar</button>
                    <button type="submit" class="flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-sky-600 to-indigo-600 px-4 py-2 text-xs font-extrabold text-white shadow-lg shadow-sky-500/30 transition hover:brightness-110 disabled:opacity-60" :disabled="docForm.processing">{{ docForm.processing ? 'Creando…' : 'Crear y editar' }}</button>
                </div>
            </form>
        </div>
        <ConfirmModal :show="pendingDelete !== null" title="Enviar a la papelera" :message="`«${pendingDelete?.label}» se moverá a la papelera y se eliminará definitivamente en 7 días.`" confirm-label="Enviar a papelera" @confirm="confirmRemove" @cancel="pendingDelete = null" />
        <ConfirmModal :show="pendingForceDelete !== null" danger title="Eliminar definitivamente" :message="`«${pendingForceDelete?.label}» se eliminará para siempre y no se podrá recuperar.`" confirm-label="Eliminar para siempre" @confirm="confirmForceDelete" @cancel="pendingForceDelete = null" />
        <ConfirmModal :show="showEmptyTrash" danger title="Vaciar papelera" :message="`Se eliminarán definitivamente los ${items.length} elementos de la papelera. Esta acción no se puede deshacer.`" confirm-label="Vaciar papelera" @confirm="emptyTrash" @cancel="showEmptyTrash = false" />
        <div v-if="importWarningItem" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4 backdrop-blur-sm" @click.self="importWarningItem = null">
            <div class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl dark:bg-slate-900">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-sky-100 text-xl text-sky-600 dark:bg-sky-900/60 dark:text-sky-300">⚠️</div>
                    <div class="min-w-0">
                        <h3 class="text-lg font-extrabold text-slate-900 dark:text-white">Documento importado desde Word</h3>
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
        <div v-if="uploadVersionItem" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4 backdrop-blur-sm" @click.self="uploadVersionItem = null">
            <form class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl dark:bg-slate-900" @submit.prevent="submitUploadVersion">
                <div class="flex items-center gap-3">
                    <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-sky-500 to-indigo-600 text-white shadow-lg shadow-sky-500/30"><Upload :size="20" /></span>
                    <div><h3 class="text-lg font-extrabold text-slate-900 dark:text-white">Subir nueva versión</h3><p class="text-xs text-slate-400">El documento <span class="font-bold text-slate-600 dark:text-slate-200">{{ uploadVersionItem.title }}</span> se actualizará ⬆️</p></div>
                </div>
                <label class="mt-4 flex cursor-pointer items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-sky-200 bg-sky-50/60 p-4 text-sm font-semibold text-sky-700 transition hover:bg-sky-100 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300 dark:hover:bg-sky-950">
                    <span class="truncate">{{ uploadVersionFile ? uploadVersionFile.name : 'Selecciona un archivo .docx o .doc' }}</span>
                    <input type="file" class="hidden" accept=".doc,.docx" @change="onUploadVersionFile" />
                </label>
                <input v-model="uploadVersionNotes" class="mt-3 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-800 outline-none focus:border-sky-400 focus:ring-2 focus:ring-sky-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100" placeholder="Notas de la nueva versión (opcional)" />
                <p v-if="uploadVersionError" class="mt-2 text-sm font-semibold text-red-600 dark:text-red-400">{{ uploadVersionError }}</p>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-xl px-3.5 py-2 text-xs font-bold text-slate-500 transition hover:bg-slate-200/70 dark:text-slate-300 dark:hover:bg-slate-700" @click="uploadVersionItem = null">Cancelar</button>
                    <button type="submit" class="explorer-primary-button" :disabled="uploadingVersion">{{ uploadingVersion ? 'Subiendo…' : 'Subir versión' }}</button>
                </div>
            </form>
        </div>
        <div v-if="modifyItem" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4 backdrop-blur-sm" @click.self="cancelModify">
            <div class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl dark:bg-slate-900">
                <div class="flex items-center gap-3">
                    <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-lg shadow-indigo-500/30"><Pencil :size="20" /></span>
                    <div><h3 class="text-lg font-extrabold text-slate-900 dark:text-white">Modificar en Word</h3><p class="text-xs text-slate-400">Fidelidad total 💯</p></div>
                </div>
                <p class="mt-3 text-sm leading-relaxed text-slate-500 dark:text-slate-400">Se descargó <span class="font-bold text-slate-700 dark:text-slate-100">{{ modifyItem.title }}</span> con sus metadatos. Edita el archivo en Word y vuelve a subir los cambios aquí, o guárdalos desde el panel de Word.</p>
                <div v-if="modifyLocked" class="mt-3 flex items-center gap-2 rounded-xl bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800 dark:bg-amber-950/50 dark:text-amber-300">
                    <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-amber-500"></span>
                    Bloqueado para ti hasta que guardes cambios o canceles.
                </div>
                <p v-if="modifyError" class="mt-2 rounded-xl bg-red-50 px-3 py-2 text-sm font-semibold text-red-600 dark:bg-red-950/50 dark:text-red-400">{{ modifyError }}</p>
                <template v-if="modifyLocked">
                    <label class="mt-4 flex cursor-pointer items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-sky-200 bg-sky-50/60 p-4 text-sm font-semibold text-sky-700 transition hover:bg-sky-100 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300 dark:hover:bg-sky-950">
                        <span class="truncate">{{ modifyFile ? modifyFile.name : 'Selecciona el .docx editado en Word' }}</span>
                        <input type="file" class="hidden" accept=".doc,.docx" @change="onModifyFile" />
                    </label>
                    <input v-model="modifyNotes" class="mt-3 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-800 outline-none focus:border-sky-400 focus:ring-2 focus:ring-sky-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100" placeholder="Notas del cambio (opcional)" />
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" class="rounded-xl px-3.5 py-2 text-xs font-bold text-slate-500 transition hover:bg-slate-200/70 dark:text-slate-300 dark:hover:bg-slate-700" :disabled="modifying" @click="cancelModify">Cancelar edición</button>
                        <button type="button" class="explorer-primary-button" :disabled="modifying || !modifyFile" @click="submitModify">{{ modifying ? 'Guardando…' : 'Guardar versión' }}</button>
                    </div>
                </template>
                <div v-else class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-xl px-3.5 py-2 text-xs font-bold text-slate-500 transition hover:bg-slate-200/70 dark:text-slate-300 dark:hover:bg-slate-700" :disabled="modifying" @click="closeModify">Cerrar</button>
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
.explorer-sidebar-heading { display: flex; justify-content: space-between; align-items: center; padding: 0.25rem 0.65rem 0.75rem; color: var(--pd-text-4); font-size: 0.7rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
.explorer-sidebar-heading button { display: inline-flex; height: 1.6rem; width: 1.6rem; align-items: center; justify-content: center; border-radius: 9999px; background: var(--pd-accent-soft); color: var(--pd-accent-text); transition: transform 120ms ease; }
.explorer-sidebar-heading button:hover { transform: scale(1.12) rotate(90deg); }
.folder-tree-item { display: flex; width: 100%; align-items: center; gap: 0.55rem; border-radius: 0.8rem; padding: 0.55rem 0.8rem; color: var(--pd-text-2); font-size: 0.9rem; font-weight: 500; text-align: left; transition: background-color 120ms ease, transform 120ms ease; }
.folder-tree-item:hover { background: var(--pd-surface-2); transform: translateX(2px); }
.folder-tree-item-active { background: linear-gradient(90deg, var(--pd-accent-soft), var(--pd-surface-2)); color: var(--pd-accent-text); font-weight: 700; box-shadow: inset 3px 0 0 var(--pd-accent); }
.folder-tree-count { border-radius: 9999px; background: var(--pd-surface-3); padding: 0.1rem 0.5rem; font-size: 0.65rem; font-weight: 700; color: var(--pd-text-3); }
.folder-tree-icon { width: 0.8rem; color: var(--pd-text-4); font-size: 0.7rem; }
.explorer-main { min-width: 0; flex: 1; padding: 1.5rem; }
.stat-card { display: flex; align-items: center; gap: 0.75rem; border-radius: 1rem; border: 1px solid var(--pd-border); background: var(--pd-surface); padding: 0.8rem 1rem; box-shadow: var(--pd-shadow-sm); text-align: left; transition: transform 150ms ease, box-shadow 150ms ease, border-color 150ms ease; }
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--pd-shadow); }
.stat-active { border-color: var(--pd-accent); box-shadow: inset 0 3px 0 var(--pd-accent), var(--pd-shadow); }
.stat-icon { display: flex; height: 2.5rem; width: 2.5rem; flex-shrink: 0; align-items: center; justify-content: center; border-radius: 0.8rem; box-shadow: 0 4px 12px rgb(15 23 42 / 12%); }
.stat-num { display: block; font-size: 1.25rem; font-weight: 800; line-height: 1.1; color: var(--pd-text); }
.stat-label { display: block; font-size: 0.7rem; color: var(--pd-text-3); }
.explorer-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
.explorer-breadcrumbs { display: flex; flex-wrap: wrap; align-items: center; gap: 0.35rem; color: var(--pd-text-3); font-size: 0.85rem; }
.crumb { display: inline-flex; align-items: center; gap: 0.35rem; border-radius: 9999px; border: 1px solid transparent; padding: 0.3rem 0.75rem; font-weight: 600; transition: all 120ms ease; }
.crumb:hover { border-color: var(--pd-border); background: var(--pd-surface); color: var(--pd-accent-text); }
.crumb-active { background: var(--pd-accent-soft); color: var(--pd-accent-text); }
.explorer-dropzone { display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem; margin-bottom: 1rem; border: 2px dashed var(--pd-accent); border-radius: 1rem; background: linear-gradient(120deg, var(--pd-accent-soft), var(--pd-surface)); padding: 0.9rem 1.1rem; color: var(--pd-text-3); font-size: 0.82rem; box-shadow: var(--pd-shadow-sm); transition: transform 150ms ease, box-shadow 150ms ease; }
.explorer-dropzone-active { transform: scale(1.01); box-shadow: var(--pd-shadow); border-style: solid; }
.drop-icon { display: inline-flex; height: 2rem; width: 2rem; align-items: center; justify-content: center; border-radius: 0.7rem; background: linear-gradient(120deg, var(--pd-accent), var(--pd-accent-2)); color: #fff; }
.explorer-dropzone label { cursor: pointer; color: var(--pd-accent-text); font-weight: 700; text-decoration: underline; text-underline-offset: 2px; }
.explorer-dropzone button { margin-left: 0.5rem; border-radius: 9999px; background: linear-gradient(120deg, var(--pd-accent), var(--pd-accent-2)); color: #fff; font-weight: 700; padding: 0.4rem 0.9rem; box-shadow: var(--pd-shadow-sm); transition: transform 120ms ease; }
.explorer-dropzone button:hover { transform: translateY(-1px); }
.drop-chosen { font-weight: 600; color: var(--pd-text-2); }
.explorer-content-panel { overflow: hidden; border: 1px solid var(--pd-border); border-radius: 1rem; background: var(--pd-surface); box-shadow: var(--pd-shadow-lg); }
.explorer-list-header, .explorer-row { display: grid; grid-template-columns: minmax(12rem, 2fr) minmax(7rem, 0.8fr) minmax(8.5rem, 1fr) minmax(8.5rem, 1fr) minmax(10rem, 1.1fr); align-items: center; gap: 0.9rem; padding: 0.7rem 1.1rem; }
.explorer-list-header { border-bottom: 1px solid var(--pd-border); background: linear-gradient(180deg, var(--pd-surface-2), var(--pd-surface-3)); color: var(--pd-text-4); font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; }
.explorer-row { min-height: 4rem; border-bottom: 1px solid var(--pd-border); font-size: 0.8rem; transition: background-color 120ms ease; }
.explorer-row:last-child { border-bottom: 0; }
.explorer-row:hover { background: var(--pd-surface-2); }
.explorer-row-active { background: var(--pd-accent-soft); box-shadow: inset 3px 0 0 var(--pd-accent); }
.explorer-row-active:hover { background: var(--pd-accent-soft); }
.explorer-name-cell { display: flex; min-width: 0; align-items: center; gap: 0.65rem; text-align: left; }
.explorer-actions-head { text-align: right; }
.explorer-actions button, .explorer-actions a { white-space: nowrap; }
.explorer-empty { padding: 3.5rem 1rem; text-align: center; }
.empty-illo { display: inline-flex; height: 4.5rem; width: 4.5rem; align-items: center; justify-content: center; border-radius: 1.5rem; background: linear-gradient(135deg, var(--pd-accent-soft), var(--pd-surface-3)); color: var(--pd-accent-text); box-shadow: var(--pd-shadow-sm); }
.explorer-content-panel button:focus-visible, .explorer-content-panel a:focus-visible, .explorer-header-button:focus-visible, .explorer-action-button:focus-visible, .explorer-primary-button:focus-visible, .folder-tree-item:focus-visible, .mini-btn:focus-visible { outline: 2px solid var(--pd-accent); outline-offset: 2px; }
.explorer-item-icon { display: flex; width: 2.4rem; height: 2.4rem; flex: 0 0 2.4rem; align-items: center; justify-content: center; border-radius: 0.75rem; box-shadow: 0 4px 12px rgb(15 23 42 / 12%); transition: transform 150ms ease; }
.explorer-name-cell:hover .explorer-item-icon { transform: scale(1.1) rotate(-3deg); }
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
.explorer-pagination { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 0.75rem; margin-top: 0.9rem; }
.explorer-pagination-info { color: var(--pd-text-3); font-size: 0.78rem; }
.explorer-pagination .explorer-action-button:disabled { cursor: not-allowed; opacity: 0.42; transform: none; box-shadow: none; }
.grid-card { border-radius: 1rem; border: 1px solid var(--pd-border); background: var(--pd-surface); padding: 1rem; box-shadow: var(--pd-shadow-sm); cursor: pointer; transition: transform 160ms ease, box-shadow 160ms ease, border-color 160ms ease; }
.grid-card:hover { transform: translateY(-3px); box-shadow: var(--pd-shadow); border-color: var(--pd-accent); }
.grid-card-active { border-color: var(--pd-accent); box-shadow: inset 0 3px 0 var(--pd-accent), var(--pd-shadow); }
.grid-tile { display: flex; height: 3rem; width: 3rem; align-items: center; justify-content: center; border-radius: 0.9rem; box-shadow: 0 6px 16px rgb(15 23 42 / 14%); transition: transform 160ms ease; }
.grid-card:hover .grid-tile { transform: scale(1.08) rotate(-3deg); }
.grid-del { border-radius: 9999px; padding: 0.35rem; color: var(--pd-text-4); opacity: 0; transition: all 120ms ease; }
.grid-card:hover .grid-del { opacity: 1; }
.grid-del:hover { background: var(--pd-danger-hover); color: var(--pd-danger-text); }
.mini-btn { display: inline-flex; align-items: center; justify-content: center; border-radius: 0.6rem; border: 1px solid var(--pd-border); background: var(--pd-surface); color: var(--pd-text-3); padding: 0.35rem 0.45rem; transition: all 120ms ease; }
.mini-btn:hover:not(:disabled) { border-color: var(--pd-accent); background: var(--pd-accent-soft); color: var(--pd-accent-text); transform: translateY(-1px); }
.mini-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.animate-item { animation: riseIn 0.35s ease both; }
@keyframes riseIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
@media (max-width: 1250px) { .explorer-sidebar { width: 17rem; flex-basis: 17rem; } .explorer-list-header, .explorer-row { grid-template-columns: minmax(11rem, 2fr) minmax(7rem, 0.8fr) minmax(8.5rem, 1fr) minmax(10rem, 1.1fr); } .explorer-list-header span:nth-child(4), .explorer-row > span:nth-child(4) { display: none; } }
@media (max-width: 900px) { .explorer-sidebar { width: 14rem; flex-basis: 14rem; } .explorer-list-header, .explorer-row { grid-template-columns: minmax(0, 1fr) auto; } .explorer-list-header span:nth-child(2), .explorer-row > span:nth-child(2), .explorer-list-header span:nth-child(4), .explorer-row > span:nth-child(4) { display: none; } .explorer-row > .explorer-actions { grid-column: 1 / -1; justify-content: flex-start; } }
@media (max-width: 640px) {
    .explorer-layout { display: block; }
    .explorer-sidebar { width: 100%; min-height: auto; max-height: 16rem; overflow-y: auto; border-right: 0; border-bottom: 1px solid var(--pd-border); }
    .explorer-main { padding: 0.75rem; }
    .explorer-toolbar > .flex { flex-wrap: wrap; }
    .explorer-list-header { display: none; }
    .explorer-row { grid-template-columns: minmax(0, 1fr); gap: 0.5rem; padding: 0.75rem; }
    .explorer-name-cell { flex-wrap: wrap; }
    .explorer-actions { flex-direction: row; flex-wrap: wrap; justify-content: flex-start; }
    .explorer-actions button, .explorer-actions a { max-width: 100%; }
    .grid-del { opacity: 1; }
}
.explorer-trash-note { color: var(--pd-text-4); font-size: 0.75rem; }
</style>
