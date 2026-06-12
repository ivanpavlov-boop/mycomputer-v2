<template>
  <div>
    <div v-if="product.promo_price" class="flex items-baseline gap-2">
      <span class="text-2xl font-bold text-red-600">{{ money(product.promo_price) }}</span>
      <span class="text-sm text-slate-500 line-through">{{ money(product.price) }}</span>
    </div>
    <div v-else class="text-2xl font-bold text-slate-950">{{ money(product.price) }}</div>
    <p class="mt-1 text-xs text-slate-500">Цената е с ДДС</p>
  </div>
</template>

<script setup lang="ts">
import type { ProductCard } from '~/types/api'

defineProps<{ product: ProductCard }>()

const money = (value: string | number) => `${Number(value).toFixed(2)} лв.`
</script>
