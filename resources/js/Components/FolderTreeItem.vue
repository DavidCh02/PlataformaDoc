<script setup>
import { computed } from 'vue';
import { ChevronDown, ChevronRight, Folder, FolderOpen } from 'lucide-vue-next';

const props = defineProps({
    folder: { type: Object, required: true },
    currentFolder: { type: [Number, null], default: null },
    level: { type: Number, default: 0 },
});

const emit = defineEmits(['open']);
const isActive = computed(() => Number(props.currentFolder) === Number(props.folder.id));
const hasKids = computed(() => (props.folder.children?.length || 0) > 0);
</script>

<template>
    <div>
        <button
            type="button"
            class="folder-tree-item"
            :class="{ 'folder-tree-item-active': isActive }"
            :style="{ paddingLeft: `${0.75 + level * 0.9}rem` }"
            :title="folder.name"
            @click="emit('open', folder.id)"
        >
            <span class="folder-tree-icon">
                <ChevronDown v-if="hasKids" :size="12" />
                <ChevronRight v-else :size="12" />
            </span>
            <component :is="isActive ? FolderOpen : Folder" :size="15" class="shrink-0" :class="isActive ? '' : 'text-amber-500/80'" />
            <span class="flex-1 truncate">{{ folder.name }}</span>
            <span v-if="hasKids" class="folder-tree-count">{{ folder.children.length }}</span>
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
