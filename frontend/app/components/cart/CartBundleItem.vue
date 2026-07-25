<template>
  <div class="flex gap-4 border-b border-slate-100 py-4 last:border-b-0">
    <div class="flex h-20 w-20 items-center justify-center rounded-md bg-slate-100 text-2xl text-slate-300">□</div>
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
      <ul v-if="readinessMessages.length" class="mt-2 space-y-1 text-xs text-amber-700">
        <li v-for="message in readinessMessages" :key="message">{{ message }}</li>
      </ul>
      <p v-if="operationError" class="mt-2 text-xs text-red-700" role="alert">
        {{ operationError.message }}
      </p>
    </div>
    <div class="text-right">
      <div class="font-semibold">{{ formatPrice(item.total_price) }}</div>
      <button
        class="mt-2 text-sm font-semibold text-red-700 disabled:cursor-not-allowed disabled:opacity-50"
        :disabled="pending"
        :aria-busy="pending"
        @click="remove"
      >
        {{ pending ? 'Премахване…' : 'Премахни' }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { CartBundleItem } from '~/types/api'
import { cartReadinessMessages } from '~/utils/cartReadiness'

const props = defineProps<{ item: CartBundleItem }>()
const cart = useCartStore()

const operationKey = computed(() => `bundle:remove:${props.item.id}`)
const pending = computed(() => cart.isOperationPending(operationKey.value))
const operationError = computed(() => cart.errorFor(operationKey.value))
const readinessMessages = computed(() => cartReadinessMessages(props.item.readiness))
const formatPrice = (value: string | number) => `${Number(value).toFixed(2)} ${cart.currency}`

async function remove() {
  await cart.removeBundle(props.item.id).catch(() => null)
}
</script>
