<template>
  <div v-if="meta && meta.last_page > 1" class="mt-8 flex items-center justify-center gap-2">
    <UiBaseButton variant="secondary" :disabled="meta.current_page <= 1" @click="$emit('change', meta.current_page - 1)">Назад</UiBaseButton>
    <span class="px-3 text-sm text-slate-600">Страница {{ meta.current_page }} от {{ meta.last_page }}</span>
    <UiBaseButton variant="secondary" :disabled="meta.current_page >= meta.last_page" @click="$emit('change', meta.current_page + 1)">Напред</UiBaseButton>
  </div>
</template>

<script setup lang="ts">
defineProps<{ meta?: { current_page: number; last_page: number } }>()
defineEmits<{ change: [page: number] }>()
</script>
