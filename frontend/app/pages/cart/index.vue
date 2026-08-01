<template>
  <div>
    <LayoutBreadcrumbs :items="[{ label: 'Количка' }]" />
    <div class="container-page">
      <h1 class="text-3xl font-bold">Количка</h1>

      <div v-if="cart.isUnresolved || cart.isInitialLoading" class="mt-6" role="status">
        <p class="mb-3 text-sm text-slate-600">Зареждаме количката…</p>
        <UiLoadingState :count="2" />
      </div>

      <div v-else-if="!cart.cart" class="mt-6 max-w-2xl space-y-4">
        <UiErrorState title="Не успяхме да заредим количката" :text="cart.error?.message" />
        <UiBaseButton
          variant="secondary"
          :disabled="cart.isOperationPending('sync')"
          :aria-busy="cart.isOperationPending('sync')"
          @click="retry"
        >
          {{ cart.isOperationPending('sync') ? 'Зареждане…' : 'Опитай отново' }}
        </UiBaseButton>
      </div>

      <div v-else class="mt-6 grid gap-6 lg:grid-cols-[1fr_320px]">
        <div class="surface p-4">
          <p
            v-if="cart.cartLevelError"
            class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700"
            role="alert"
          >
            {{ cart.cartLevelError.message }}
          </p>
          <div
            v-if="cart.readiness && !cart.readiness.can_checkout && (cart.items.length || cart.bundleItems.length)"
            class="mb-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800"
          >
            <p class="font-semibold">Количката съдържа продукти, които трябва да прегледате.</p>
            <ul class="mt-2 space-y-1">
              <li v-for="problem in readinessProblems" :key="problem.key">
                <span class="font-medium">{{ problem.label }}:</span>
                {{ problem.messages.join(' ') }}
              </li>
            </ul>
          </div>

          <CartItem v-for="item in cart.items" :key="item.id" :item="item" />
          <CartBundleItem v-for="item in cart.bundleItems" :key="item.id" :item="item" />

          <div v-if="cart.isConfirmedEmpty">
            <UiEmptyState
              title="Количката е празна"
              text="Все още няма добавени продукти."
            />
            <NuxtLink class="mt-4 inline-flex text-sm font-semibold text-brand-700" to="/catalog">
              Към каталога
            </NuxtLink>
          </div>

          <div v-if="cart.hasConfirmedContent" class="mt-4 border-t border-slate-100 pt-4">
            <button
              class="text-sm font-semibold text-red-700 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="cart.isOperationPending('clear')"
              :aria-busy="cart.isOperationPending('clear')"
              @click="clearCart"
            >
              {{ cart.isOperationPending('clear') ? 'Изчистване…' : 'Изчисти количката' }}
            </button>
            <p v-if="cart.errorFor('clear')" class="mt-2 text-xs text-red-700" role="alert">
              {{ cart.errorFor('clear')?.message }}
            </p>
          </div>
        </div>

        <aside v-if="cart.hasConfirmedContent" class="surface h-fit p-5">
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
            v-if="cart.canCheckout && !cart.isMutating"
            to="/checkout"
            class="mt-5 block rounded-md bg-brand-600 px-4 py-2 text-center text-sm font-semibold text-white"
          >
            Към поръчка
          </NuxtLink>
          <p v-else class="mt-5 rounded-md bg-slate-100 px-4 py-2 text-center text-sm text-slate-600">
            Прегледайте количката преди поръчка
          </p>

          <UiBaseButton
            v-if="auth.isAuthenticated"
            class="mt-3 w-full"
            variant="secondary"
            :disabled="cart.isMutating"
            @click="requestQuote"
          >
            Заяви оферта за количката
          </UiBaseButton>
        </aside>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { cartReadinessMessages } from '~/utils/cartReadiness'

const cart = useCartStore()
const b2b = useB2B()
const router = useRouter()
const auth = useAuthStore()

definePageMeta({
  middleware: 'commerce-entry',
})

await cart.sync().catch(() => null)

const readinessProblems = computed(() => [
  ...cart.items
    .filter(item => item.readiness && !item.readiness.can_checkout)
    .map(item => ({
      key: `item:${item.id}`,
      label: item.product.name,
      messages: cartReadinessMessages(item.readiness),
    })),
  ...cart.bundleItems
    .filter(item => item.readiness && !item.readiness.can_checkout)
    .map(item => ({
      key: `bundle:${item.id}`,
      label: item.bundle_name,
      messages: cartReadinessMessages(item.readiness),
    })),
])

async function retry() {
  await cart.sync().catch(() => null)
}

async function clearCart() {
  await cart.clear().catch(() => null)
}

async function requestQuote() {
  const response = await b2b.requestCartQuote({
    notes: 'Заявка за оферта от количка',
  }) as { data: { id: number } }
  await router.push(`/account/b2b/quotes/${response.data.id}`)
}

useSeo().page('Количка', 'Преглед на избраните продукти.', '/cart')
useHead({
  meta: [
    { name: 'robots', content: 'noindex, nofollow, noarchive' },
  ],
})
</script>
