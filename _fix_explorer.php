<?php
// Fix Explorer.vue encoding and props issues

$file = 'resources\\js\\Pages\\Explorer.vue';
$content = file_get_contents($file);

// Fix character encoding
$content = utf8_encode($content);
$content = str_replace(['Â¿', 'Â¡', 'Ã¡', 'Ã©', 'Ã­', 'Ã³', 'Ãº', 'Ã±', 'Ã¼', 'Ã‘', 'â€“', 'â€¦'], 
                       ['¿', '¡', 'á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü', 'Ñ', '–', '…'], $content);

// Fix props - replace defineProps with proper toRefs pattern
$old_props = 'defineProps({
    folders: { type: Array, required: true },
    files: { type: Array, required: true },
    documents: { type: Array, required: true },
    currentFolder: { type: [Number, null], default: null },
    breadcrumbs: { type: Array, required: true },
    showTrash: { type: Boolean, default: false },
    isAdmin: { type: Boolean, default: false },
    userIdFilter: { type: [Number, null], default: null },
    allUsers: { type: Array, default: null },
});';

$new_props = 'import { ref, toRefs } from \'vue\';
import { Head, Link, router, useForm } from \'@inertiajs/vue3\';

const props = toRefs(defineProps({
    folders: { type: Array, required: true },
    files: { type: Array, required: true },
    documents: { type: Array, required: true },
    currentFolder: { type: [Number, null], default: null },
    breadcrumbs: { type: Array, required: true },
    showTrash: { type: Boolean, default: false },
    isAdmin: { type: Boolean, default: false },
    userIdFilter: { type: [Number, null], default: null },
    allUsers: { type: Array, default: null },
}));';

$content = str_replace($old_props, $new_props, $content);

// Fix import line
$content = str_replace("import { ref } from 'vue';", '', $content);
$content = str_replace("import { Head, Link, router, useForm } from '@inertiajs/vue3';", '', $content);

// Update getRouteParams function to use props.*
$old_getRoute = 'const getRouteParams = () => {
    const params = { folder_id: useFormPageFolder() };
    if (props.isAdmin && props.userIdFilter) {
        params.user_id = props.userIdFilter;
    }
    if (props.showTrash) {
        params.trash = true;
    }
    return params;
};';

$new_getRoute = 'const getRouteParams = () => {
    const params = { folder_id: getUrlFolderId() };
    if (props.isAdmin.value && props.userIdFilter.value) {
        params.user_id = props.userIdFilter.value;
    }
    if (props.showTrash.value) {
        params.trash = true;
    }
    return params;
};';

$content = str_replace($old_getRoute, $new_getRoute, $content);

// Update switchUser
$old_switch = 'const switchUser = (userId) => {
    const params = { user_id: userId || null };
    if (props.showTrash) params.trash = true;
    router.get(route(\'dashboard\'), params);
};';

$new_switch = 'const switchUser = (userId) => {
    const params = { user_id: userId || null };
    if (props.showTrash.value) params.trash = true;
    router.get(route(\'dashboard\'), params);
};';

$content = str_replace($old_switch, $new_switch, $content);

// Update toggleTrash
$old_toggle = 'const toggleTrash = () => {
    const params = getRouteParams();
    params.trash = !props.showTrash;
    router.get(route(\'dashboard\'), params);
};';

$new_toggle = 'const toggleTrash = () => {
    const params = getRouteParams();
    params.trash = !props.showTrash.value;
    router.get(route(\'dashboard\'), params);
};';

$content = str_replace($old_toggle, $new_toggle, $content);

// Replace useFormPageFolder function name with getUrlFolderId
$content = str_replace('useFormPageFolder()', 'getUrlFolderId()', $content);

// Remove duplicate useFormPageFolder function if exists
$content = preg_replace('/const useFormPageFolder = \(\) => \{.*?^};/ms', '', $content);

file_put_contents($file, $content);
echo "Explorer.vue arreglado correctamente" . PHP_EOL;