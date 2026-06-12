<template>
  <aside class="surface space-y-4 p-5">
    <div>
      <div class="text-sm text-slate-500">Цена на комплект</div>
      <div class="text-3xl font-bold text-brand-700">{{ formatPrice(bundle.price) }}</div>
      <div v-if="Number(bundle.savings) > 0" class="text-sm text-emerald-700">
        Спестявате {{ formatPrice(bundle.savings) }}
      </div>
    </div>
    <div class="flex items-center gap-3">
      <label class="text-sm font-medium" for="bundle-qty">Количество</label>
      <input id="bundle-qty" v-model.number="quantity" class="w-20 rounded-md border border-slate-300 px-3 py-2" type="number" min="1" max="20">
    </div>
    <BaseButton class="w-full" :disabled="pending" @click="add">
      {{ pending ? 'Добавяне...' : 'Добави комплекта' }}
    </BaseButton>
    <p v-if="message" class="text-sm" :class="error ? 'text-red-700' : 'text-emerald-700'">
      {{ message }}
    </p>
  </aside>
</template>

<script setup lang="ts">
import type { CartResponse, ProductBundle } from '~/types/api'

const props = defineProps<{ bundle: ProductBundle; selectedItems: Array<Record<string, unknown>> }>()

const cart = useCartStore()
const quantity = ref(1)
const pending = ref(false)
const message = ref('')
const error = ref(false)
const formatPrice = (value: string | number) => `${Number(value).toFixed(2)} лв.`

async function add() {
  pending.value = true
  message.value = ''
  error.value = false
  try {
    const response = await useCartApi().addBundle(props.bundle.id, quantity.value, props.selectedItems) as { data: CartResponse }
    cart.backendCart = response.data
    cart.backendAvailable = true
    message.value = 'Комплектът е добавен в количката.'
    await useAnalytics().addToCart({ bundle_id: props.bundle.id, quantity: quantity.value, value: Number(props.bundle.price) * quantity.value })
  } catch {
    error.value = true
    message.value = 'Комплектът не може да бъде добавен. Проверете опциите и наличността.'
  } finally {
    pending.value = false
  }
}
</script>
