<template>
  <div class="rounded-lg border border-red-200 bg-red-50 p-5 text-red-800">
    <p class="font-semibold">{{ title }}</p>
    <p class="mt-1 text-sm">{{ text }}</p>
  </div>
</template>

<script setup lang="ts">
withDefaults(defineProps<{ title?: string; text?: string }>(), {
  title: 'Възникна грешка',
  text: 'Моля, опитайте отново след малко.',
})
</script>
