<script setup>
import { ref } from 'vue';
import { NodeViewWrapper, nodeViewProps } from '@tiptap/vue-3';

const props = defineProps(nodeViewProps);

const imageRef = ref(null);

/**
 * Redimensión por arrastre: actualiza los atributos width/height del nodo
 * y estos se persisten en el HTML (getHTML/autoguardado).
 */
const startResize = (event) => {
    event.preventDefault();
    event.stopPropagation();

    const startX = event.clientX;
    const startWidth = imageRef.value?.getBoundingClientRect().width || 0;
    const startHeight = imageRef.value?.getBoundingClientRect().height || 0;
    const ratio = startHeight / startWidth;

    const onMove = (moveEvent) => {
        const newWidth = Math.max(60, Math.round(startWidth + moveEvent.clientX - startX));
        props.updateAttributes({
            width: newWidth,
            height: Math.round(newWidth * ratio),
        });
    };

    const onUp = () => {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
    };

    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
};
</script>

<template>
    <NodeViewWrapper
        class="resizable-image"
        :data-drag-handle="props.editor.isEditable ? 'true' : undefined"
    >
        <span class="relative inline-block">
            <img
                ref="imageRef"
                :src="props.node.attrs.src"
                :alt="props.node.attrs.alt || ''"
                :width="props.node.attrs.width || undefined"
                :height="props.node.attrs.height || undefined"
                class="docx-editor-image"
            />
            <span
                v-if="props.editor.isEditable && props.selected"
                class="resize-handle"
                :title="'Redimensionar imagen'"
                @mousedown="startResize"
            ></span>
        </span>
    </NodeViewWrapper>
</template>

<style>
.resizable-image { display: inline-block; vertical-align: middle; }
.resizable-image .docx-editor-image { display: inline-block; max-width: 100%; height: auto; border-radius: 0.25rem; }
.resize-handle {
    position: absolute;
    right: -5px;
    bottom: -5px;
    height: 14px;
    width: 14px;
    cursor: se-resize;
    border: 2px solid #ffffff;
    border-radius: 9999px;
    background: #1d4ed8;
    box-shadow: 0 1px 3px rgb(15 23 42 / 45%);
}
</style>