<template>
  <div class="flex flex-wrap gap-2 text-sm">
    <button class="font-semibold text-brand-700" @click="$emit('vote', 'helpful')">Полезно</button>
    <button class="font-semibold text-slate-600" @click="$emit('vote', 'not_helpful')">Не е полезно</button>
  </div>
</template>

<script setup lang="ts">
defineEmits<{ vote: ['helpful' | 'not_helpful'] }>()
</script>
