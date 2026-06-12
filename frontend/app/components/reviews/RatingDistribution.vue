<template>
  <div class="grid gap-2">
    <div v-for="rating in [5, 4, 3, 2, 1]" :key="rating" class="grid grid-cols-[32px_1fr_40px] items-center gap-3 text-sm">
      <span>{{ rating }}★</span>
      <div class="h-2 rounded bg-slate-100">
        <div class="h-2 rounded bg-amber-500" :style="{ width: percent(distribution[rating] || 0) }" />
      </div>
      <span class="text-right text-slate-500">{{ distribution[rating] || 0 }}</span>
    </div>
  </div>
</template>

<script setup lang="ts">
const props = defineProps<{ distribution: Record<number, number>, total: number }>()
const percent = (count: number) => props.total > 0 ? `${Math.round((count / props.total) * 100)}%` : '0%'
</script>
