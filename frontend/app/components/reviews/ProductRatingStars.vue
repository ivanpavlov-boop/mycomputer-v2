<template>
  <span class="inline-flex items-center gap-0.5" :aria-label="`${rating} от 5`">
    <span v-for="star in 5" :key="star" :class="star <= Math.round(rating) ? 'text-amber-500' : 'text-slate-300'">★</span>
  </span>
</template>

<script setup lang="ts">
defineProps<{ rating: number }>()
</script>
