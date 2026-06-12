<template>
  <div class="grid gap-3">
    <label
      v-for="method in methods"
      :key="method.code"
      class="rounded-md border p-3 text-sm"
      :class="modelValue === method.code ? 'border-brand-500 bg-brand-50' : 'border-slate-200'"
    >
      <input class="mr-2" type="radio" :value="method.code" :checked="modelValue === method.code" @change="$emit('update:modelValue', method.code)">
      <span class="font-semibold">{{ method.name }}</span>
      <span v-if="method.description" class="mt-1 block text-slate-600">{{ method.description }}</span>
    </label>
  </div>
</template>

<script setup lang="ts">
import type { PaymentMethod } from '~/types/api'

defineProps<{ modelValue: string; methods: PaymentMethod[] }>()
defineEmits<{ 'update:modelValue': [value: string] }>()
</script>
