<template>
  <BaseSelect :model-value="modelValue" @update:model-value="$emit('update:modelValue', $event)">
    <option value="">Изберете куриер</option>
    <option v-for="provider in providers" :key="provider.code" :value="provider.code">{{ provider.name }}</option>
  </BaseSelect>
</template>

<script setup lang="ts">
import type { ShippingProvider } from '~/types/api'

defineProps<{ modelValue?: string; providers: ShippingProvider[] }>()
defineEmits<{ 'update:modelValue': [value: string] }>()
</script>
