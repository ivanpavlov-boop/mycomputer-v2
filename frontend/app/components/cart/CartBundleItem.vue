<template>
  <div class="flex gap-4 border-b border-slate-100 py-4 last:border-b-0">
    <div class="flex h-20 w-20 items-center justify-center rounded-md bg-slate-100 text-2xl text-slate-300">▦</div>
    <div class="min-w-0 flex-1">
      <div class="font-semibold">{{ item.bundle_name }}</div>
      <div class="mt-1 text-sm text-slate-600">
        Комплект · {{ item.quantity }} бр.
      </div>
      <ul class="mt-2 space-y-1 text-xs text-slate-500">
        <li v-for="line in item.selected_items" :key="String(line.product_id)">
          {{ String(line.name || '') }} × {{ Number(line.quantity || 1) }}
        </li>
      </ul>
    </div>
    <div class="text-right">
      <div class="font-semibold">{{ formatPrice(item.total_price) }}</div>
      <button class="mt-2 text-sm font-semibold text-red-700" @click="remove">Премахни</button>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { CartBundleItem, CartResponse } from '~/types/api'

const props = defineProps<{ item: CartBundleItem }>()
const cart = useCartStore()

const formatPrice = (value: string | number) => `${Number(value).toFixed(2)} лв.`

async function remove() {
  const response = await useCartApi().removeBundle(props.item.id) as { data: CartResponse }
  cart.backendCart = response.data
  cart.backendAvailable = true
}
</script>
