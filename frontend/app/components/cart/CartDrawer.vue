<template>
  <Teleport to="body">
    <div v-if="ui.cartOpen" class="fixed inset-0 z-50 bg-slate-950/40" @click="ui.cartOpen = false" />
    <aside v-if="ui.cartOpen" class="fixed right-0 top-0 z-50 flex h-full w-full max-w-md flex-col bg-white shadow-soft">
      <div class="flex items-center justify-between border-b border-slate-200 p-4">
        <p class="font-semibold">Количка</p>
        <button class="rounded-md p-2 hover:bg-slate-100" aria-label="Затвори" @click="ui.cartOpen = false">×</button>
      </div>
      <div class="flex-1 overflow-auto p-4">
        <LoadingState v-if="cart.isInitialLoading" :count="2" />
        <div v-else-if="cart.cart">
          <CartItem v-for="item in cart.items" :key="item.id" :item="item" />
          <CartBundleItem v-for="item in cart.bundleItems" :key="item.id" :item="item" />
          <EmptyState
            v-if="!cart.items.length && !cart.bundleItems.length"
            title="Количката е празна"
            text="Добавете продукти, за да продължите."
          />
        </div>
        <div v-else class="space-y-3">
          <ErrorState :text="cart.error?.message" />
          <BaseButton variant="secondary" @click="retry">Опитай отново</BaseButton>
        </div>
        <p v-if="cart.cart && cart.error" class="mt-3 rounded-md bg-red-50 p-3 text-sm text-red-700">
          {{ cart.error.message }}
        </p>
      </div>
      <div class="border-t border-slate-200 p-4">
        <div class="flex justify-between font-semibold">
          <span>Общо</span>
          <span>{{ cart.subtotal.toFixed(2) }} {{ cart.currency }}</span>
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

watch(() => ui.cartOpen, (isOpen) => {
  if (isOpen && cart.status === 'idle') {
    cart.sync().catch(() => null)
  }
})

function retry() {
  cart.sync().catch(() => null)
}
</script>
