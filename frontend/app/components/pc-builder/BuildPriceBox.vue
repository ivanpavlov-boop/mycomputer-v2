<template>
  <section class="surface p-5">
    <div class="text-sm font-semibold text-slate-500">Обща стойност</div>
    <div class="mt-1 text-3xl font-extrabold text-brand-700">{{ formatted }}</div>
    <p class="mt-2 text-sm text-slate-500">Цените се пресмятат от каталога и се проверяват отново при добавяне в количката.</p>
  </section>
</template>

<script setup lang="ts">
const props = defineProps<{ total: string | number }>()
const formatted = computed(() => `${Number(props.total || 0).toFixed(2)} лв.`)
</script>
