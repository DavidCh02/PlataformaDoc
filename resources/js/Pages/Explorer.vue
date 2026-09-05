<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FolderTreeItem from '@/Components/FolderTreeItem.vue';
import DocxPreviewModal from '@/Components/DocxPreviewModal.vue';
import PdfPreviewModal from '@/Components/PdfPreviewModal.vue';

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
const selectedFile = ref(null);
const selectedDocument = ref(null);
const previewMode = ref(null);
const isDragging = ref(false);
const folderForm = useForm({ name: '', parent_id: null });
const uploadForm = useForm({ file: null, folder_id: null });

const currentFolderLabel = computed(() => props.breadcrumbs.at(-1)?.name || 'Este equipo');
const items = computed(() => [
    ...props.folders.map(folder => ({ ...folder, kind: 'folder', sort: 0, label: folder.name })),
    ...props.files.map(file => ({ ...file, kind: 'file', sort: 1, label: file.original_name })),
    ...props.documents.map(document => ({ ...document, kind: 'document', sort: 1, label: document.title })),
].sort((left, right) => left.sort - right.sort || left.label.localeCompare(right.label)));

const folderIdFromUrl = () => {
    const value = new URLSearchParams(window.location.search).get('folder_id');
    return value ? Number(value) : null;
};
const openFolder = folderId => router.get(route('dashboard'), { folder_id: folderId });
const toggleTrash = () => router.get(route('dashboard'), { trash: !props.showTrash });
const createFolder = () => {
    folderForm.parent_id = folderIdFromUrl();
    folderForm.post(route('folders.store'), { preserveScroll: true, onSuccess: () => { folderForm.reset(); showFolderModal.value = false; } });
};
const selectFile = event => { selectedFile.value = event.target.files?.[0] || null; uploadForm.file = selectedFile.value; };
const uploadFile = () => {
    uploadForm.folder_id = folderIdFromUrl();
    uploadForm.post(route('files.store'), { forceFormData: true, preserveScroll: true, onSuccess: () => { selectedFile.value = null; uploadForm.reset(); } });
};
const handleDrop = event => { isDragging.value = false; const [file] = event.dataTransfer.files; if (file) { selectedFile.value = file; uploadForm.file = file; } };
const createDocument = () => {
    const title = window.prompt('Título del documento');
    if (title?.trim()) router.post(route('documents.store'), { title: title.trim(), folder_id: folderIdFromUrl() });
};
const isPdf = file => file.mime_type === 'application/pdf' || file.original_name?.toLowerCase().endsWith('.pdf');
const isDocx = file => file.original_name?.toLowerCase().endsWith('.docx');
const previewFile = file => { if (isPdf(file)) { previewMode.value = 'pdf'; selectedFile.value = file; } else if (isDocx(file)) { previewMode.value = 'docx'; selectedFile.value = file; } };
const previewDocument = document => { selectedDocument.value = document; selectedFile.value = null; previewMode.value = null; };
const closePreview = () => { selectedFile.value = null; selectedDocument.value = null; previewMode.value = null; };
const remove = (kind, id) => {
    if (!window.confirm('¿Deseas enviar este elemento a la papelera?')) return;
    router.delete(route(`${kind}.destroy`, id), { preserveScroll: true });
};
const restore = (kind, id) => router.post(route(`${kind}.restore`, id), {}, { preserveScroll: true });
const forceDelete = (kind, id) => {
    if (window.confirm('¿Deseas eliminarlo definitivamente?')) router.delete(route(`${kind}.force-destroy`, id), { preserveScroll: true });
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
const documentPreviewHtml = document => document.content || '<p>Documento vacío.</p>';
</script>

<template>
    <Head title="Explorador" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><p class="text-sm text-slate-500">PlataformaDoc</p><h2 class="text-2xl font-semibold text-slate-900">{{ showTrash ? 'Papelera de reciclaje' : currentFolderLabel }}</h2></div>
                <div class="flex gap-2"><button class="explorer-header-button" type="button" @click="toggleTrash">{{ showTrash ? 'Volver al explorador' : 'Papelera' }}</button></div>
            </div>
        </template>

        <div class="explorer-layout">
            <aside class="explorer-sidebar">
                <div class="explorer-sidebar-heading"><span>Ubicaciones</span><button type="button" title="Nueva carpeta" @click="showFolderModal = true">+</button></div>
                <button type="button" class="folder-tree-item" :class="{ 'folder-tree-item-active': !currentFolder && !showTrash }" @click="openFolder(null)"><span>⌂</span><span>Este equipo</span></button>
                <FolderTreeItem v-for="folder in folderTree" :key="folder.id" :folder="folder" :current-folder="currentFolder" @open="openFolder" />
                <div class="mt-auto border-t border-slate-200 pt-3"><button type="button" class="folder-tree-item" :class="{ 'folder-tree-item-active': showTrash }" @click="toggleTrash"><span>⌫</span><span>Papelera</span></button></div>
            </aside>

            <main class="explorer-main">
                <div v-if="$page.props.flash?.success" class="mb-4 rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ $page.props.flash.success }}</div>
                <div class="explorer-toolbar">
                    <nav class="explorer-breadcrumbs" aria-label="Ruta"><button type="button" @click="openFolder(null)">Este equipo</button><template v-for="breadcrumb in breadcrumbs" :key="breadcrumb.id"><span>/</span><button type="button" @click="openFolder(breadcrumb.id)">{{ breadcrumb.name }}</button></template></nav>
                    <div v-if="!showTrash" class="flex gap-2"><button class="explorer-action-button" type="button" @click="showFolderModal = true">Nueva carpeta</button><button class="explorer-action-button" type="button" @click="createDocument">Nuevo documento</button></div>
                </div>
                <div v-if="!showTrash" class="explorer-dropzone" :class="{ 'explorer-dropzone-active': isDragging }" @dragover.prevent="isDragging = true" @dragleave.prevent="isDragging = false" @drop.prevent="handleDrop"><span>Arrastra archivos aquí o</span><label>selecciona uno<input type="file" class="hidden" accept=".pdf,.png,.jpg,.jpeg,.docx" @change="selectFile" /></label><button v-if="selectedFile && !previewMode" type="button" @click="uploadFile">{{ uploadForm.processing ? 'Subiendo...' : `Subir ${selectedFile.name}` }}</button></div>
                <section class="explorer-content-panel">
                    <div class="explorer-list-header"><span>Nombre</span><span>Tipo</span><span>Creado</span><span>Modificado</span><span>Acciones</span></div>
                    <div v-if="!items.length" class="px-5 py-16 text-center text-sm text-slate-500">No hay elementos en esta carpeta.</div>
                    <div v-for="item in items" :key="`${item.kind}-${item.id}`" class="explorer-row" @dblclick="item.kind === 'folder' ? openFolder(item.id) : router.visit(item.kind === 'document' ? route('documents.edit', item.id) : route('files.edit', item.id))">
                        <button type="button" class="explorer-name-cell" @click="item.kind === 'folder' ? openFolder(item.id) : item.kind === 'file' ? previewFile(item) : previewDocument(item)"><span class="explorer-item-icon">{{ item.kind === 'folder' ? '▰' : item.kind === 'document' ? '▤' : isPdf(item) ? '▧' : '▱' }}</span><span class="min-w-0 truncate font-medium text-slate-800">{{ item.label }}</span></button>
                        <span class="explorer-type">{{ item.kind === 'folder' ? 'Carpeta' : item.kind === 'document' ? 'Documento' : `${item.mime_type} · ${formatSize(item.file_size)}` }}</span>
                        <span class="explorer-meta"><strong>{{ ownerName(item) }}</strong><small>{{ createdDate(item) }}</small></span>
                        <span class="explorer-meta"><strong>{{ modifierName(item) }}</strong><small>{{ modifiedDate(item) }}</small></span>
                        <div class="explorer-actions"><template v-if="showTrash"><button type="button" @click="restore(`${item.kind}s`, item.id)">Restaurar</button><button class="danger" type="button" @click="forceDelete(`${item.kind}s`, item.id)">Eliminar</button></template><template v-else><button v-if="item.kind === 'file' && (isPdf(item) || isDocx(item))" type="button" @click="previewFile(item)">Vista previa</button><Link v-if="item.kind === 'document'" :href="route('documents.edit', item.id)">Abrir</Link><a v-if="item.kind === 'file'" :href="route('files.download', item.id)">Descargar</a><button class="danger" type="button" @click="remove(`${item.kind}s`, item.id)">Eliminar</button></template></div>
                    </div>
                </section>
                <aside v-if="selectedDocument" class="explorer-document-preview"><div class="explorer-preview-heading"><div><p class="text-xs uppercase tracking-wide text-slate-400">Vista previa</p><h3 class="truncate font-semibold text-slate-900">{{ selectedDocument.title }}</h3></div><button type="button" title="Cerrar vista previa" @click="closePreview">×</button></div><div class="explorer-preview-body" v-html="documentPreviewHtml(selectedDocument)"></div><Link :href="route('documents.edit', selectedDocument.id)" class="explorer-primary-button mt-4 inline-block">Abrir editor</Link></aside>
            </main>
        </div>

        <div v-if="showFolderModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="showFolderModal = false"><form class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl" @submit.prevent="createFolder"><h3 class="text-lg font-semibold">Nueva carpeta</h3><input v-model="folderForm.name" class="mt-4 w-full rounded-md border-slate-300" placeholder="Nombre de carpeta" required autofocus /><p v-if="folderForm.errors.name" class="mt-2 text-sm text-red-600">{{ folderForm.errors.name }}</p><div class="mt-5 flex justify-end gap-2"><button type="button" class="explorer-action-button" @click="showFolderModal = false">Cancelar</button><button type="submit" class="explorer-primary-button">Crear</button></div></form></div>
        <DocxPreviewModal v-if="selectedFile && previewMode === 'docx'" :file="selectedFile" :url="route('files.blob', selectedFile.id)" @close="closePreview" @edit="router.visit(route('files.edit', selectedFile.id))" />
        <PdfPreviewModal v-if="selectedFile && previewMode === 'pdf'" :file="selectedFile" :url="route('files.blob', selectedFile.id)" @close="closePreview" />
    </AuthenticatedLayout>
</template>

<style>
.explorer-layout { display: flex; min-height: calc(100vh - 8rem); background: #f1f5f9; }
.explorer-sidebar { display: flex; width: 16rem; flex: 0 0 16rem; flex-direction: column; border-right: 1px solid #dbe2ea; background: #f8fafc; padding: 1rem 0.65rem; }
.explorer-sidebar-heading { display: flex; justify-content: space-between; padding: 0.25rem 0.65rem 0.75rem; color: #64748b; font-size: 0.7rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
.explorer-sidebar-heading button { color: #2563eb; font-size: 1.2rem; line-height: 1; }
.folder-tree-item { display: flex; width: 100%; align-items: center; gap: 0.5rem; border-radius: 0.3rem; padding: 0.45rem 0.65rem; color: #475569; font-size: 0.875rem; text-align: left; }
.folder-tree-item:hover, .folder-tree-item-active { background: #dbeafe; color: #1d4ed8; }
.folder-tree-icon { width: 0.8rem; color: #94a3b8; font-size: 0.7rem; }
.explorer-main { min-width: 0; flex: 1; padding: 1.25rem; }
.explorer-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
.explorer-breadcrumbs { display: flex; flex-wrap: wrap; align-items: center; gap: 0.45rem; color: #64748b; font-size: 0.875rem; }
.explorer-breadcrumbs button:hover { color: #0f172a; }
.explorer-action-button, .explorer-header-button { border: 1px solid #cbd5e1; border-radius: 0.3rem; background: white; padding: 0.45rem 0.7rem; color: #334155; font-size: 0.8rem; font-weight: 600; }
.explorer-primary-button { border-radius: 0.3rem; background: #1d4ed8; padding: 0.45rem 0.8rem; color: white; font-size: 0.8rem; font-weight: 600; }
.explorer-dropzone { margin-bottom: 1rem; border: 1px dashed #94a3b8; border-radius: 0.35rem; background: white; padding: 0.75rem 1rem; color: #64748b; font-size: 0.8rem; }
.explorer-dropzone-active { border-color: #2563eb; background: #eff6ff; }
.explorer-dropzone label { margin-left: 0.25rem; cursor: pointer; color: #2563eb; font-weight: 600; }
.explorer-dropzone button { margin-left: 0.75rem; color: #047857; font-weight: 700; }
.explorer-content-panel { overflow: hidden; border: 1px solid #dbe2ea; border-radius: 0.35rem; background: white; box-shadow: 0 4px 14px rgb(15 23 42 / 5%); }
.explorer-document-preview { margin-top: 1rem; border: 1px solid #dbe2ea; border-radius: 0.35rem; background: white; padding: 1rem; box-shadow: 0 4px 14px rgb(15 23 42 / 5%); }
.explorer-preview-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.75rem; }
.explorer-preview-heading button { color: #64748b; font-size: 1.35rem; line-height: 1; }
.explorer-preview-body { max-height: 18rem; overflow: auto; padding: 1rem 0.25rem; color: #334155; font-size: 0.9rem; line-height: 1.6; }
.explorer-preview-body h1, .explorer-preview-body h2, .explorer-preview-body h3 { margin: 0.7em 0 0.35em; color: #0f172a; }
.explorer-preview-body p { margin-bottom: 0.65em; }
.explorer-preview-body img { max-width: 100%; height: auto; }
.explorer-preview-body table { width: 100%; border-collapse: collapse; }
.explorer-preview-body td, .explorer-preview-body th { border: 1px solid #cbd5e1; padding: 0.35rem; }
.explorer-list-header, .explorer-row { display: grid; grid-template-columns: minmax(14rem, 2fr) minmax(9rem, 1fr) minmax(10rem, 1.1fr) minmax(10rem, 1.1fr) minmax(11rem, 1.2fr); align-items: center; gap: 1rem; padding: 0.7rem 1rem; }
.explorer-list-header { border-bottom: 1px solid #e2e8f0; color: #64748b; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }
.explorer-row { min-height: 3.4rem; border-bottom: 1px solid #f1f5f9; font-size: 0.8rem; }
.explorer-row:hover { background: #f8fafc; }
.explorer-name-cell { display: flex; min-width: 0; align-items: center; gap: 0.65rem; text-align: left; }
.explorer-item-icon { width: 1.4rem; color: #2563eb; font-size: 1.2rem; text-align: center; }
.explorer-type { overflow: hidden; color: #64748b; text-overflow: ellipsis; white-space: nowrap; }
.explorer-meta { display: flex; min-width: 0; flex-direction: column; color: #334155; }
.explorer-meta small { overflow: hidden; color: #94a3b8; text-overflow: ellipsis; white-space: nowrap; }
.explorer-actions { display: flex; flex-wrap: wrap; gap: 0.65rem; }
.explorer-actions button, .explorer-actions a { color: #2563eb; font-size: 0.75rem; font-weight: 600; }
.explorer-actions .danger { color: #dc2626; }
@media (max-width: 900px) { .explorer-sidebar { width: 13rem; flex-basis: 13rem; } .explorer-list-header, .explorer-row { grid-template-columns: minmax(11rem, 2fr) minmax(7rem, 1fr) minmax(8rem, 1fr) minmax(8rem, 1fr); } .explorer-list-header span:nth-child(2), .explorer-row > .explorer-type { display: none; } }
@media (max-width: 640px) { .explorer-layout { display: block; } .explorer-sidebar { width: 100%; min-height: auto; border-right: 0; border-bottom: 1px solid #dbe2ea; } .explorer-sidebar > .folder-tree-item:nth-of-type(n+7) { display: none; } .explorer-main { padding: 0.75rem; } .explorer-list-header { display: none; } .explorer-row { grid-template-columns: minmax(0, 1fr) auto; gap: 0.5rem; padding: 0.75rem; } .explorer-meta { grid-column: 1; grid-row: 2; } .explorer-actions { grid-column: 2; grid-row: 1 / span 2; flex-direction: column; align-items: flex-end; } }
</style>
