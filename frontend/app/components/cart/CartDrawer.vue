<template>
  <Teleport to="body">
    <div v-if="ui.cartOpen" class="fixed inset-0 z-50 bg-slate-950/40" @click="ui.cartOpen = false" />
    <aside
      v-if="ui.cartOpen"
      class="fixed right-0 top-0 z-50 flex h-full w-full max-w-md flex-col bg-white shadow-soft"
      role="dialog"
      aria-modal="true"
      aria-labelledby="cart-drawer-title"
    >
      <div class="flex items-center justify-between border-b border-slate-200 p-4">
        <p id="cart-drawer-title" class="font-semibold">Количка</p>
        <button class="rounded-md p-2 hover:bg-slate-100" aria-label="Затвори" @click="ui.cartOpen = false">×</button>
      </div>
      <div class="flex-1 overflow-auto p-4" aria-live="polite">
        <div v-if="cart.isUnresolved || cart.isInitialLoading" role="status">
          <p class="mb-3 text-sm text-slate-600">Зареждаме количката…</p>
          <LoadingState :count="2" />
        </div>
        <div v-else-if="cart.cart">
          <p
            v-if="cart.cartLevelError"
            class="mb-3 rounded-md bg-red-50 p-3 text-sm text-red-700"
            role="alert"
          >
            {{ cart.cartLevelError.message }}
          </p>
          <p
            v-if="cart.readiness && !cart.readiness.can_checkout && cart.hasConfirmedContent"
            class="mb-3 rounded-md bg-amber-50 p-3 text-sm text-amber-800"
          >
            Количката съдържа продукти, които трябва да прегледате.
          </p>
          <CartItem v-for="item in cart.items" :key="item.id" :item="item" />
          <CartBundleItem v-for="item in cart.bundleItems" :key="item.id" :item="item" />
          <EmptyState
            v-if="cart.isConfirmedEmpty"
            title="Количката е празна"
            text="Добавете продукти, за да продължите."
          />
        </div>
        <div v-else class="space-y-3">
          <ErrorState :text="cart.error?.message" />
          <BaseButton
            variant="secondary"
            :disabled="cart.isOperationPending('sync')"
            :aria-busy="cart.isOperationPending('sync')"
            @click="retry"
          >
            {{ cart.isOperationPending('sync') ? 'Зареждане…' : 'Опитай отново' }}
          </BaseButton>
        </div>
      </div>
      <div v-if="cart.cart" class="border-t border-slate-200 p-4">
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
