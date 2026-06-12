<template>
  <input
    :value="modelValue"
    class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
    @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
  >
</template>

<script setup lang="ts">
defineProps<{ modelValue?: string | number | null }>()
defineEmits<{ 'update:modelValue': [value: string] }>()
</script>
