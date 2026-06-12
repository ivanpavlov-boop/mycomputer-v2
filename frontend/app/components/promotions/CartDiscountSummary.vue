<template>
  <div v-if="discount > 0 || shippingDiscount > 0" class="space-y-1 text-sm">
    <div v-if="discount > 0" class="flex justify-between text-emerald-700">
      <span>Promotion discounts</span>
      <span>-{{ discount.toFixed(2) }} лв.</span>
    </div>
    <div v-if="shippingDiscount > 0" class="flex justify-between text-emerald-700">
      <span>Shipping discount</span>
      <span>-{{ shippingDiscount.toFixed(2) }} лв.</span>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{ discount: number, shippingDiscount: number }>()
</script>
