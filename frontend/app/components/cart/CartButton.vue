<template>
  <button
    class="relative rounded-md bg-slate-100 px-3 py-2 text-sm font-semibold hover:bg-slate-200"
    :aria-busy="isResolving"
    :aria-label="buttonLabel"
    @click="ui.cartOpen = true"
  >
    {{ isResolving ? 'Зареждане…' : 'Количка' }}
    <span v-if="cart.count" class="ml-1 rounded-full bg-brand-600 px-2 py-0.5 text-xs text-white">{{ cart.count }}</span>
  </button>
</template>

<script setup lang="ts">
const cart = useCartStore()
const ui = useUiStore()
const isHydrated = ref(false)
const isResolving = computed(() => !isHydrated.value || cart.isUnresolved || cart.isInitialLoading)
const buttonLabel = computed(() => isResolving.value
  ? 'Зареждаме количката'
  : `Количка, ${cart.count} продукта`)

onMounted(() => {
  isHydrated.value = true

  if (cart.status === 'idle') {
    cart.sync().catch(() => null)
  }
})
</script>
