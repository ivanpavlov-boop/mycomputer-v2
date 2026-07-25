<template>
  <div>
    <Breadcrumbs :items="[{ label: 'Количка' }]" />
    <div class="container-page">
      <h1 class="text-3xl font-bold">Количка</h1>

      <LoadingState v-if="cart.isInitialLoading" class="mt-6" :count="2" />

      <div v-else-if="!cart.cart" class="mt-6 max-w-2xl space-y-4">
        <ErrorState :text="cart.error?.message" />
        <BaseButton variant="secondary" @click="retry">Опитай отново</BaseButton>
      </div>

      <div v-else class="mt-6 grid gap-6 lg:grid-cols-[1fr_320px]">
        <div class="surface p-4">
          <p v-if="cart.error" class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
            {{ cart.error.message }}
          </p>
          <p
            v-if="cart.readiness && !cart.readiness.can_checkout && (cart.items.length || cart.bundleItems.length)"
            class="mb-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800"
          >
            Част от съдържанието на количката изисква преглед преди поръчка.
          </p>

          <CartItem v-for="item in cart.items" :key="item.id" :item="item" />
          <CartBundleItem v-for="item in cart.bundleItems" :key="item.id" :item="item" />

          <EmptyState
            v-if="!cart.items.length && !cart.bundleItems.length"
            title="Количката е празна"
            text="Все още няма добавени продукти."
          />
        </div>

        <aside class="surface h-fit p-5">
          <p class="text-lg font-semibold">Обобщение</p>
          <div class="mt-4 flex justify-between">
            <span>Междинна сума</span>
            <span class="font-semibold">{{ cart.subtotal.toFixed(2) }} {{ cart.currency }}</span>
          </div>
          <PromotionsCartDiscountSummary
            class="mt-3"
            :discount="Number(cart.cart.promotion_discount_total)"
            :shipping-discount="Number(cart.cart.shipping_discount)"
          />
          <PromotionsCouponInput class="mt-4" />

          <NuxtLink
            v-if="cart.canCheckout"
            to="/checkout"
            class="mt-5 block rounded-md bg-brand-600 px-4 py-2 text-center text-sm font-semibold text-white"
          >
            Към поръчка
          </NuxtLink>
          <p v-else class="mt-5 rounded-md bg-slate-100 px-4 py-2 text-center text-sm text-slate-600">
            Прегледайте количката преди поръчка
          </p>

          <BaseButton
            class="mt-3 w-full"
            variant="secondary"
            @click="requestQuote"
          >
            Заяви оферта за количката
          </BaseButton>
        </aside>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
const cart = useCartStore()
const b2b = useB2B()
const router = useRouter()
const auth = useAuthStore()

await cart.sync().catch(() => null)

function retry() {
  cart.sync().catch(() => null)
}

async function requestQuote() {
  await auth.fetchUser()
  if (!auth.isAuthenticated) {
    await router.push('/login')
    return
  }

  const response = await b2b.requestCartQuote({
    notes: 'Заявка за оферта от количка',
  }) as { data: { id: number } }
  await router.push(`/account/b2b/quotes/${response.data.id}`)
}

useSeo().page('Количка', 'Преглед на избраните продукти.', '/cart')
</script>
