<template>
  <div>
    <LayoutBreadcrumbs :items="[{ label: 'Потвърждение на поръчка' }]" />
    <section class="container-page">
      <div class="surface max-w-2xl p-8">
        <div v-if="status === 'idle' || status === 'pending'" role="status">
          <p class="text-sm font-semibold text-brand-700">Зареждаме потвърждението за поръчката…</p>
          <UiLoadingState class="mt-4" :count="2" />
        </div>

        <div v-else-if="confirmation">
          <p class="text-sm font-semibold text-emerald-700">Поръчката е приета</p>
          <h1 class="mt-2 text-3xl font-bold">Благодарим Ви!</h1>
          <div class="mt-5 grid gap-2 text-sm text-slate-700">
            <p>Номер на поръчка: <strong>{{ confirmation.order_number }}</strong></p>
            <p>Обща сума: <strong>{{ confirmation.grand_total }} {{ confirmation.currency }}</strong></p>
            <p>Статус: <strong>{{ confirmation.order_status }}</strong></p>
            <p>Плащане: <strong>{{ confirmation.payment_method.name }} · {{ confirmation.payment_status }}</strong></p>
            <p>Потвърждението е изпратено до <strong>{{ confirmation.customer_email_masked }}</strong>.</p>
          </div>

          <PaymentsPaymentActionPanel
            :presentation="confirmation.payment.presentation"
            mode="guest"
          />

          <p class="mt-5 text-slate-600">Екипът ни ще се свърже с Вас при нужда от уточнение.</p>
          <NuxtLink to="/" class="mt-6 inline-flex rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white">
            Към началото
          </NuxtLink>
        </div>

        <div v-else>
          <p class="text-sm font-semibold text-amber-700">Потвърждението за поръчката не е налично.</p>
          <p class="mt-3 text-sm text-slate-600">
            Ако сте направили поръчка, проверете имейла си или се свържете с екипа ни.
          </p>
          <NuxtLink to="/" class="mt-6 inline-flex rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white">
            Към началото
          </NuxtLink>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
const route = useRoute()
const router = useRouter()
const confirmationApi = useCheckoutConfirmation()
const analytics = useAnalytics()
const purchaseEmitted = ref(false)

definePageMeta({
  middleware: 'commerce-confirmation',
})

const { data, status } = useLazyAsyncData(
  'checkout-confirmation',
  () => confirmationApi.get(),
)
const confirmation = computed(() => data.value?.data ?? null)

watch(confirmation, async (value) => {
  if (!import.meta.client || !value || purchaseEmitted.value) {
    return
  }

  purchaseEmitted.value = true
  await analytics.purchase({
    order_number: value.order_number,
    value: Number(value.grand_total),
    currency: value.currency,
  })
}, { immediate: true })

onMounted(async () => {
  if (route.fullPath !== route.path) {
    await router.replace(route.path)
  }
})

useSeo().page('Потвърждение на поръчка', 'Статус на направена поръчка.', '/checkout/success')
useHead({
  meta: [
    { name: 'robots', content: 'noindex, nofollow, noarchive' },
    { name: 'referrer', content: 'no-referrer' },
  ],
})
</script>
