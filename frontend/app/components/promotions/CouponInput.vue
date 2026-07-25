<template>
  <form class="flex gap-2" @submit.prevent="apply">
    <BaseInput v-model="code" :disabled="pending" placeholder="Промо код" />
    <BaseButton type="submit" :disabled="pending">Приложи</BaseButton>
    <button
      v-if="cart.couponCode"
      class="text-sm font-semibold text-red-600 disabled:opacity-50"
      type="button"
      :disabled="pending"
      @click="remove"
    >
      Премахни
    </button>
  </form>
</template>

<script setup lang="ts">
const cart = useCartStore()
const code = ref(cart.couponCode || '')
const pending = computed(() => cart.isOperationPending('coupon'))

watch(() => cart.couponCode, value => {
  code.value = value || ''
})

async function apply() {
  if (!code.value.trim()) return

  await cart.applyCoupon(code.value.trim()).catch(() => null)
}

async function remove() {
  const confirmed = await cart.removeCoupon().catch(() => null)

  if (confirmed) {
    code.value = ''
  }
}
</script>
