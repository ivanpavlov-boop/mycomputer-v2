<template>
  <div v-if="gifts.length" class="rounded-md bg-brand-50 p-3 text-sm text-brand-900">
    <div class="font-semibold">Gift products</div>
    <div v-for="gift in gifts" :key="gift.product_id">Product #{{ gift.product_id }} x {{ gift.quantity }}</div>
  </div>
</template>

<script setup lang="ts">
defineProps<{ gifts: Array<{ product_id: number, quantity: number }> }>()
</script>
