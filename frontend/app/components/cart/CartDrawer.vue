<template>
  <Teleport to="body">
    <div v-if="ui.cartOpen" class="fixed inset-0 z-50 bg-slate-950/40" @click="ui.cartOpen = false" />
    <aside v-if="ui.cartOpen" class="fixed right-0 top-0 z-50 flex h-full w-full max-w-md flex-col bg-white shadow-soft">
      <div class="flex items-center justify-between border-b border-slate-200 p-4">
        <p class="font-semibold">Количка</p>
        <button class="rounded-md p-2 hover:bg-slate-100" @click="ui.cartOpen = false">✕</button>
      </div>
      <div class="flex-1 overflow-auto p-4">
        <CartItem v-for="item in cart.backendItems" :key="item.id" :line="{ product: item.product, quantity: item.quantity }" :item-id="item.id" />
        <CartItem v-for="line in cart.lines" :key="line.product.id" :line="line" />
        <EmptyState v-if="!cart.backendItems.length && !cart.lines.length" title="Количката е празна" text="Добавете продукти, за да продължите." />
      </div>
      <div class="border-t border-slate-200 p-4">
        <div class="flex justify-between font-semibold">
          <span>Общо</span>
          <span>{{ cart.subtotal.toFixed(2) }} EUR</span>
        </div>
        <NuxtLink to="/cart" class="mt-4 block rounded-md bg-brand-600 px-4 py-2 text-center text-sm font-semibold text-white" @click="ui.cartOpen = false">
          Към количката
        </NuxtLink>
      </div>
    </aside>
  </Teleport>
</template>

<script setup lang="ts">
const ui = useUiStore()
const cart = useCartStore()
</script>
