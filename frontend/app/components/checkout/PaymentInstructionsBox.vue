<template>
  <div v-if="text" class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
    {{ text }}
  </div>
</template>

<script setup lang="ts">
defineProps<{ text?: string | null }>()
</script>
