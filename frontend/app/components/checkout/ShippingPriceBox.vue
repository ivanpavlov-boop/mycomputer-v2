<template>
  <div class="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
    <div class="flex justify-between">
      <span>Доставка</span>
      <strong>{{ price.toFixed(2) }} лв.</strong>
    </div>
    <p class="mt-1 text-slate-500">{{ estimated || 'Изчислява се според избрания метод.' }}</p>
  </div>
</template>

<script setup lang="ts">
defineProps<{ price: number; estimated?: string }>()
</script>
