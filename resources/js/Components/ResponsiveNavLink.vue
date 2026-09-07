<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
    },
});

const classes = computed(() =>
    props.active
        ? 'block w-full px-4 py-2 text-start text-sm font-semibold text-sky-800 bg-sky-100/70 ring-1 ring-sky-200/70 rounded-lg focus:outline-none transition duration-150 ease-in-out'
        : 'block w-full px-4 py-2 text-start text-sm font-medium text-slate-600 rounded-lg hover:text-slate-900 hover:bg-slate-100 focus:outline-none focus:text-slate-900 focus:bg-slate-100 transition duration-150 ease-in-out',
);
</script>

<template>
    <Link :href="href" :class="classes">
        <slot />
    </Link>
</template>
