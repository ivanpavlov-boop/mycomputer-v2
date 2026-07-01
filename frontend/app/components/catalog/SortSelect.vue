<template>
  <UiBaseSelect :model-value="modelValue" @update:model-value="emitSortValue">
    <option v-for="option in catalogSortOptions" :key="option.value" :value="option.value">
      {{ option.label }}
    </option>
  </UiBaseSelect>
</template>

<script setup lang="ts">
import { catalogSortOptions, normalizeCatalogSort, type CatalogSort } from '~/utils/catalogSorts'

defineProps<{ modelValue?: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: CatalogSort] }>()

function emitSortValue(value: unknown) {
  emit('update:modelValue', normalizeCatalogSort(value))
}
</script>
