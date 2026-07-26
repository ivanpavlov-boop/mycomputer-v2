<template>
  <div class="flex gap-3 border-b border-slate-100 py-4">
    <div class="h-16 w-16 rounded-md bg-slate-100" />
    <div class="min-w-0 flex-1">
      <NuxtLink :to="`/p/${item.product.slug}`" class="line-clamp-2 text-sm font-semibold">
        {{ item.product.name }}
      </NuxtLink>
      <p class="mt-1 text-sm text-slate-600">
        {{ item.is_gift ? 'Безплатно' : money(item.unit_price, cart.currency) }}
      </p>
      <p v-if="item.is_gift" class="mt-2 text-xs font-semibold text-emerald-700">
        Подарък от активна промоция
      </p>
      <p v-if="item.is_gift" class="mt-1 text-xs text-slate-500">
        Количеството и премахването се управляват от промоцията.
      </p>
      <ul v-else-if="readinessMessages.length" class="mt-2 space-y-1 text-xs text-amber-700">
        <li v-for="message in readinessMessages" :key="message">{{ message }}</li>
      </ul>
      <form
        v-if="!item.is_gift"
        class="mt-2 flex flex-wrap items-center gap-2"
        :aria-busy="updatePending"
        @submit.prevent="submitQuantity"
      >
        <label class="sr-only" :for="`cart-quantity-${item.id}`">Количество</label>
        <UiBaseInput
          :id="`cart-quantity-${item.id}`"
          v-model="draftQuantity"
          :disabled="updatePending || removePending"
          type="number"
          min="1"
          max="20"
          inputmode="numeric"
          class="max-w-20"
          @update:model-value="quantitySaved = false"
        />
        <UiBaseButton
          type="submit"
          variant="secondary"
          :disabled="!canSubmitQuantity"
        >
          {{ updatePending ? 'Обновяване…' : 'Обнови' }}
        </UiBaseButton>
        <button
          class="text-sm font-semibold text-red-600 disabled:cursor-not-allowed disabled:opacity-50"
          type="button"
          :disabled="updatePending || removePending"
          @click="remove"
        >
          {{ removePending ? 'Премахване…' : 'Премахни' }}
        </button>
      </form>
      <p v-if="quantitySaved" class="mt-2 text-xs text-emerald-700" role="status">
        Количеството е обновено.
      </p>
      <p v-if="operationError" class="mt-2 text-xs text-red-700" role="alert">
        {{ operationError.message }}
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { CartItem } from '~/types/api'
import { cartReadinessMessages } from '~/utils/cartReadiness'

const props = defineProps<{ item: CartItem }>()
const cart = useCartStore()

const draftQuantity = ref(String(props.item.quantity))
const quantitySaved = ref(false)
const updateKey = computed(() => `update:${props.item.id}`)
const removeKey = computed(() => `remove:${props.item.id}`)
const updatePending = computed(() => cart.isOperationPending(updateKey.value))
const removePending = computed(() => cart.isOperationPending(removeKey.value))
const readinessMessages = computed(() => cartReadinessMessages(props.item.readiness))
const operationError = computed(() => cart.errorFor(updateKey.value) || cart.errorFor(removeKey.value))
const parsedQuantity = computed(() => Number(draftQuantity.value))
const canSubmitQuantity = computed(() => Number.isInteger(parsedQuantity.value)
  && parsedQuantity.value >= 1
  && parsedQuantity.value <= 20
  && parsedQuantity.value !== props.item.quantity
  && !updatePending.value
  && !removePending.value)

const money = (value: string | number, currency = 'EUR') => new Intl.NumberFormat('bg-BG', {
  style: 'currency',
  currency,
}).format(Number(value))

watch(() => props.item.quantity, (quantity) => {
  draftQuantity.value = String(quantity)
})

async function submitQuantity() {
  if (!canSubmitQuantity.value) {
    return
  }

  const confirmed = await cart.update(props.item.id, parsedQuantity.value).catch(() => null)

  if (confirmed) {
    quantitySaved.value = true
    draftQuantity.value = String(
      confirmed.items.find(item => item.id === props.item.id)?.quantity ?? props.item.quantity,
    )
  }
}

async function remove() {
  quantitySaved.value = false
  await cart.remove(props.item.id).catch(() => null)
}
</script>
