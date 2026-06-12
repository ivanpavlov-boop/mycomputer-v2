<template>
  <form class="flex gap-2" @submit.prevent="apply">
    <BaseInput v-model="code" placeholder="Promo code" />
    <BaseButton type="submit">Apply</BaseButton>
    <button v-if="cart.backendCart?.coupon_code" class="text-sm font-semibold text-red-600" type="button" @click="remove">Remove</button>
  </form>
</template>

<script setup lang="ts">
const cart = useCartStore()
const code = ref(cart.backendCart?.coupon_code || '')

async function apply() {
  if (!code.value.trim()) return
  const api = useCartApi()
  const response = await api.applyCoupon(code.value.trim()) as { data: any }
  cart.backendCart = response.data
}

async function remove() {
  const api = useCartApi()
  const response = await api.removeCoupon() as { data: any }
  cart.backendCart = response.data
  code.value = ''
}
</script>
