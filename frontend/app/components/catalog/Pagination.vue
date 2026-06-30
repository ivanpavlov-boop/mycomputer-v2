<template>
  <div v-if="hasPages" class="mt-8 flex items-center justify-center gap-2">
    <UiBaseButton variant="secondary" :disabled="currentPage <= 1" @click="$emit('change', currentPage - 1)">Назад</UiBaseButton>
    <span class="px-3 text-sm text-slate-600">Страница {{ currentPage }} от {{ lastPage }}</span>
    <UiBaseButton variant="secondary" :disabled="currentPage >= lastPage" @click="$emit('change', currentPage + 1)">Напред</UiBaseButton>
  </div>
</template>

<script setup lang="ts">
const props = defineProps<{ meta?: { current_page?: number; last_page?: number } }>()
defineEmits<{ change: [page: number] }>()

const currentPage = computed(() => props.meta?.current_page ?? 1)
const lastPage = computed(() => props.meta?.last_page ?? 1)
const hasPages = computed(() => lastPage.value > 1)
</script>
