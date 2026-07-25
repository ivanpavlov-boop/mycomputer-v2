<template>
  <div class="flex gap-3 border-b border-slate-100 py-4">
    <div class="h-16 w-16 rounded-md bg-slate-100" />
    <div class="min-w-0 flex-1">
      <NuxtLink :to="`/p/${item.product.slug}`" class="line-clamp-2 text-sm font-semibold">
        {{ item.product.name }}
      </NuxtLink>
      <p class="mt-1 text-sm text-slate-600">{{ money(item.unit_price, cart.currency) }}</p>
      <p v-if="item.is_gift" class="mt-2 text-xs font-semibold text-emerald-700">
        Подарък от активна промоция
      </p>
      <p v-else-if="item.readiness && !item.readiness.can_checkout" class="mt-2 text-xs text-amber-700">
        {{ readinessMessage }}
      </p>
      <div v-if="!item.is_gift" class="mt-2 flex items-center gap-2">
        <BaseInput
          :model-value="item.quantity"
          :disabled="isPending"
          type="number"
          min="1"
          :max="item.readiness?.stock.max_purchasable_quantity || 20"
          class="max-w-20"
          @update:model-value="update"
        />
        <button
          class="text-sm font-semibold text-red-600 disabled:cursor-not-allowed disabled:opacity-50"
          :disabled="isPending"
          @click="remove"
        >
          Премахни
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { CartItem } from '~/types/api'

const props = defineProps<{ item: CartItem }>()
const cart = useCartStore()

const isPending = computed(() => cart.isOperationPending(`update:${props.item.id}`)
  || cart.isOperationPending(`remove:${props.item.id}`))
const readinessMessage = 'Продуктът трябва да бъде прегледан преди поръчка.'

const money = (value: string | number, currency = 'EUR') => new Intl.NumberFormat('bg-BG', {
  style: 'currency',
  currency,
}).format(Number(value))

async function update(value: string) {
  await cart.update(props.item.id, Number(value)).catch(() => null)
}

async function remove() {
  await cart.remove(props.item.id).catch(() => null)
}
</script>
