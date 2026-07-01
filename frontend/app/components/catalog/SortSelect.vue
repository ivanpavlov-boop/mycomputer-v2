<template>
  <select
    :value="currentSort"
    class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
    aria-label="Сортиране на каталога"
    @change="handleSortChange"
  >
    <option v-for="option in catalogSortOptions" :key="option.value" :value="option.value">
      {{ option.label }}
    </option>
  </select>
</template>

<script setup lang="ts">
import { catalogSortOptions, normalizeCatalogSort, type CatalogSort } from '~/utils/catalogSorts'

const props = defineProps<{ modelValue?: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: CatalogSort] }>()

const currentSort = computed(() => normalizeCatalogSort(props.modelValue))

function handleSortChange(event: Event) {
  const value = (event.target as HTMLSelectElement | null)?.value

  emit('update:modelValue', normalizeCatalogSort(value))
}
</script>
