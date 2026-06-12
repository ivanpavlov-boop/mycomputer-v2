<template>
  <button class="relative rounded-md bg-slate-100 px-3 py-2 text-sm font-semibold hover:bg-slate-200" @click="ui.cartOpen = true">
    Количка
    <span v-if="cart.count" class="ml-1 rounded-full bg-brand-600 px-2 py-0.5 text-xs text-white">{{ cart.count }}</span>
  </button>
</template>

<script setup lang="ts">
const cart = useCartStore()
const ui = useUiStore()
</script>
