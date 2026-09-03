<script setup>
import { computed } from 'vue';

const props = defineProps({
    folder: { type: Object, required: true },
    currentFolder: { type: [Number, null], default: null },
    level: { type: Number, default: 0 },
});

const emit = defineEmits(['open']);
const isActive = computed(() => Number(props.currentFolder) === Number(props.folder.id));
</script>

<template>
    <div>
        <button
            type="button"
            class="folder-tree-item"
            :class="{ 'folder-tree-item-active': isActive }"
            :style="{ paddingLeft: `${0.75 + level * 0.9}rem` }"
            @click="emit('open', folder.id)"
        >
            <span class="folder-tree-icon">{{ folder.children?.length ? '▾' : '▸' }}</span>
            <span class="truncate">{{ folder.name }}</span>
        </button>
        <FolderTreeItem
            v-for="child in folder.children"
            :key="child.id"
            :folder="child"
            :current-folder="currentFolder"
            :level="level + 1"
            @open="emit('open', $event)"
        />
    </div>
</template>
