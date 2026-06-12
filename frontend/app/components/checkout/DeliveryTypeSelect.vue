<template>
  <div class="grid gap-2 sm:grid-cols-2">
    <label class="rounded-md border p-3 text-sm" :class="modelValue === 'office' ? 'border-brand-500 bg-brand-50' : 'border-slate-200'">
      <input class="mr-2" type="radio" value="office" :checked="modelValue === 'office'" @change="$emit('update:modelValue', 'office')">
      До офис
    </label>
    <label class="rounded-md border p-3 text-sm" :class="modelValue === 'address' ? 'border-brand-500 bg-brand-50' : 'border-slate-200'">
      <input class="mr-2" type="radio" value="address" :checked="modelValue === 'address'" @change="$emit('update:modelValue', 'address')">
      До адрес
    </label>
  </div>
</template>

<script setup lang="ts">
defineProps<{ modelValue: string }>()
defineEmits<{ 'update:modelValue': [value: string] }>()
</script>
