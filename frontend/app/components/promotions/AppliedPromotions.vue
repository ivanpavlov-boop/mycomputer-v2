<template>
  <div v-if="promotions.length" class="space-y-2">
    <div v-for="promotion in promotions" :key="promotion.id" class="flex justify-between rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
      <span>{{ promotion.name }}</span>
      <span>-{{ Number(promotion.discount || 0).toFixed(2) }} EUR</span>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{ promotions: Array<Record<string, any>> }>()
</script>
