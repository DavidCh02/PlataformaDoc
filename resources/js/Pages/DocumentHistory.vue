<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DocxPreviewModal from '@/Components/DocxPreviewModal.vue';
import { usePermissions } from '@/composables/usePermissions';

const props = defineProps({
    document: { type: Object, required: true },
    current_version: { type: [String, null], default: null },
    versions: { type: Array, default: () => [] },
    canDownload: { type: Boolean, default: false },
});

const { can } = usePermissions();
const currentUserId = Number(usePage().props.auth?.user?.id);
const canForceUnlock = computed(() => props.document.is_locked && (
    props.document.locked_by_id === currentUserId || can('docs.edit_realtime')
));

const forceUnlock = () => {
    if (!window.confirm(
        '¿Liberar el bloqueo manualmente? Si la persona sigue editando en Word, sus cambios guardados serán rechazados.',
    )) return;
    router.post(route('documents.unlock', props.document.id), { preserveScroll: true });
};

const openCommentVersion = ref(null);
const commentForm = useForm({ comment: '' });

const openComments = version => {
    openCommentVersion.value = openCommentVersion.value === version.id ? null : version.id;
    commentForm.reset();
};
const submitComment = version => {
    commentForm.post(route('document-versions.annotations', version.id), {
        preserveScroll: true,
        onSuccess: () => {
            commentForm.reset();
            openCommentVersion.value = null;
            router.reload({ preserveScroll: true });
        },
    });
};
const formatDate = iso => iso ? new Date(iso).toLocaleString('es-MX') : '';
const formatSize = bytes => {
    if (!bytes) return '—';
    const units = ['B', 'KB', 'MB', 'GB'];
    const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    return `${(bytes / 1024 ** index).toFixed(index ? 1 : 0)} ${units[index]}`;
};
const authorName = version => version.user?.name || 'Usuario desconocido';
const isCurrent = version => props.current_version === version.version_number;
const goBack = () => window.history.back();

// Vista previa por versión: el modal espera un objeto con id/original_name;
// se le pasa un descriptor sintético y el binario de la versión.
const previewVersion = ref(null);
const previewFileFor = version => ({
    id: `version-${version.id}`,
    original_name: `${props.document.title} · ${version.version_number}.docx`,
});
const goToEditor = () => router.visit(route('documents.edit', props.document.id));
</script>

<template>
    <Head :title="`Historial · ${document.title}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">PlataformaDoc · Historial de versiones</p>
                    <h2 class="text-2xl font-semibold text-slate-900 dark:text-white">{{ document.title }}</h2>
                </div>
                <div class="flex gap-2">
                    <button type="button" class="explorer-action-button" title="Volver a la página anterior" @click="goBack"><ArrowLeft :size="14" class="mr-1 inline align-text-bottom" />Volver</button>
                    <Link :href="route('documents.edit', document.id)" class="explorer-action-button">Abrir editor</Link>
                    <Link :href="route('dashboard')" class="explorer-action-button">Explorador</Link>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8">
            <div v-if="$page.props.flash?.success" class="mb-4 rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:bg-emerald-950/70 dark:text-emerald-300">
                {{ $page.props.flash.success }}
            </div>

            <div class="mb-6 flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800">
                <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold"
                      :class="document.is_locked ? 'bg-amber-50 text-amber-700 ring-1 ring-amber-200 dark:bg-amber-950/50 dark:text-amber-300 dark:ring-amber-900' : 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-300 dark:ring-emerald-900'">
                    {{ document.is_locked ? `Bloqueado por ${document.locked_by || 'otro usuario'}` : 'Disponible' }}
                </span>
                <span v-if="document.file_name" class="text-xs text-slate-500 dark:text-slate-400">Archivo original: {{ document.file_name }}</span>
                <span class="text-xs text-slate-500 dark:text-slate-400">Versión actual: <strong class="text-slate-800 dark:text-slate-100">{{ current_version || '—' }}</strong></span>
            </div>

            <div v-if="document.is_locked" class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300">
                Para editar este documento, abre <strong>public/word-addin/index.html</strong> desde el Add-in de Microsoft Word
                (o descarga el manifest de ejemplo desde <code class="rounded bg-amber-100 px-1 dark:bg-amber-900">docs/word-addin</code>),
                bloquea el documento y guárdalo indicando los cambios realizados.
                <button v-if="canForceUnlock" type="button"
                        class="mt-3 inline-flex items-center rounded-md border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-700 transition hover:bg-amber-100 dark:border-amber-700 dark:bg-slate-800 dark:text-amber-300 dark:hover:bg-amber-900/40"
                        @click="forceUnlock">
                    Liberar bloqueo manualmente
                </button>
            </div>

            <section v-if="!versions.length" class="rounded-xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400">
                Este documento todavía no tiene versiones registradas desde el Add-in de Word.
            </section>

            <ol v-else class="relative space-y-8 before:absolute before:left-[15px] before:top-1 before:bottom-1 before:w-px before:bg-slate-200 dark:before:bg-slate-700">
                <li v-for="version in versions" :key="version.id" class="relative flex gap-4">
                    <span class="relative z-10 mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white shadow"
                          :class="isCurrent(version) ? 'bg-sky-600' : 'bg-slate-400 dark:bg-slate-600'">
                        {{ version.version_number.replace('v', '') }}
                    </span>

                    <div class="min-w-0 flex-1 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold text-slate-900 dark:text-white">{{ version.version_number }}</span>
                                <span v-if="isCurrent(version)" class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-700 dark:bg-sky-900 dark:text-sky-300">Actual</span>
                                <span class="text-sm text-slate-500 dark:text-slate-400">· {{ authorName(version) }} · {{ formatDate(version.created_at) }}</span>
                            </div>
                            <div class="flex gap-2">
                                <button v-if="canDownload" type="button" class="explorer-action-button" title="Previsualizar esta versión" @click="previewVersion = version">
                                    Previsualizar
                                </button>
                                <a v-if="canDownload" :href="route('document-versions.download', version.id)" class="explorer-action-button" title="Descargar esta versión">
                                    Descargar
                                </a>
                                <button type="button" class="explorer-action-button" @click="openComments(version)">
                                    Comentarios ({{ version.annotations.length }})
                                </button>
                            </div>
                        </div>

                        <blockquote class="mt-3 border-l-2 border-slate-200 pl-3 text-sm leading-relaxed text-slate-700 dark:border-slate-700 dark:text-slate-300">
                            {{ version.change_summary }}
                        </blockquote>
                        <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ formatSize(version.file_size) }} · Guardado desde Word</p>

                        <ul v-if="version.annotations.length" class="mt-3 space-y-2">
                            <li v-for="annotation in version.annotations" :key="annotation.id"
                                class="rounded-lg bg-slate-50 px-3 py-2 text-sm dark:bg-slate-700/50">
                                <p class="text-slate-700 dark:text-slate-200">{{ annotation.comment }}</p>
                                <p class="mt-0.5 text-xs text-slate-400 dark:text-slate-500">
                                    {{ annotation.user?.name }} · {{ formatDate(annotation.created_at) }}
                                </p>
                            </li>
                        </ul>

                        <form v-if="openCommentVersion === version.id" class="mt-3 flex gap-2 flex-wrap" @submit.prevent="submitComment(version)">
                            <input v-model="commentForm.comment" type="text"
                                   class="min-w-0 flex-1 rounded-md border-slate-300 bg-white text-slate-900 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"
                                   placeholder="Deja una nota sobre esta versión..."
                                   maxlength="2000" required />
                            <button type="submit" class="explorer-primary-button whitespace-nowrap">
                                {{ commentForm.processing ? 'Guardando...' : 'Comentar' }}
                            </button>
                        </form>
                    </div>
                </li>
            </ol>
        </div>

        <DocxPreviewModal v-if="previewVersion" :file="previewFileFor(previewVersion)"
                          :url="route('document-versions.download', previewVersion.id)"
                          :download-url="route('document-versions.download', previewVersion.id)"
                          @close="previewVersion = null" @edit="goToEditor" />
    </AuthenticatedLayout>
</template>