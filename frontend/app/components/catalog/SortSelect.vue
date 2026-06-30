<template>
  <UiBaseSelect :model-value="modelValue" @update:model-value="$emit('update:modelValue', $event)">
    <option v-for="option in catalogSortOptions" :key="option.value" :value="option.value">
      {{ option.label }}
    </option>
  </UiBaseSelect>
</template>

<script setup lang="ts">
import { catalogSortOptions } from '~/utils/catalogSorts'

defineProps<{ modelValue?: string }>()
defineEmits<{ 'update:modelValue': [value: string] }>()
</script>
