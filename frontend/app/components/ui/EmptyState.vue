<template>
  <div class="surface p-8 text-center">
    <p class="text-lg font-semibold text-slate-900">{{ title }}</p>
    <p class="mt-2 text-sm text-slate-600">{{ text }}</p>
  </div>
</template>

<script setup lang="ts">
withDefaults(defineProps<{ title?: string; text?: string }>(), {
  title: 'Няма резултати',
  text: 'Променете филтрите или опитайте с друго търсене.',
})
</script>
