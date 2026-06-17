<template>
  <div class="flex gap-3 border-b border-slate-100 py-4">
    <div class="h-16 w-16 rounded-md bg-slate-100" />
    <div class="min-w-0 flex-1">
      <NuxtLink :to="`/p/${line.product.slug}`" class="line-clamp-2 text-sm font-semibold">{{ line.product.name }}</NuxtLink>
      <p class="mt-1 text-sm text-slate-600">{{ money(line.product.promo_price || line.product.price, line.product.currency) }}</p>
      <div class="mt-2 flex items-center gap-2">
        <BaseInput :model-value="line.quantity" type="number" class="max-w-20" @update:model-value="cart.update(line.product.id, Number($event), itemId)" />
        <button class="text-sm font-semibold text-red-600" @click="cart.remove(line.product.id, itemId)">Премахни</button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { CartLine } from '~/stores/cart'

defineProps<{ line: CartLine; itemId?: number }>()
const cart = useCartStore()

const money = (value: string | number, currency = 'EUR') => new Intl.NumberFormat('en-US', {
  style: 'currency',
  currency,
}).format(Number(value))
</script>
