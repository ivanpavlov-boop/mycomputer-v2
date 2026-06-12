<template>
  <div>
    <Breadcrumbs :items="[{ label: 'Успешна поръчка' }]" />
    <section class="container-page">
      <div class="surface max-w-2xl p-8">
        <p class="text-sm font-semibold text-emerald-700">Поръчката е приета</p>
        <h1 class="mt-2 text-3xl font-bold">Благодарим Ви!</h1>
        <div class="mt-5 grid gap-2 text-sm text-slate-700">
          <p>Номер на поръчка: <strong>{{ route.query.order }}</strong></p>
          <p>Обща сума: <strong>{{ route.query.total }} лв.</strong></p>
          <p>Имейл: <strong>{{ route.query.email }}</strong></p>
        </div>

        <BankTransferInstructions v-if="route.query.payment === 'bank_transfer'" class="mt-5" :instructions="String(route.query.instructions || '')" />
        <PaymentInstructionsBox v-if="route.query.payment === 'card'" class="mt-5" :text="`Placeholder redirect: ${route.query.redirect}`" />
        <LeasingInfoBox v-if="route.query.payment === 'leasing'" class="mt-5" />

        <p class="mt-5 text-slate-600">Ще получите потвърждение по имейл. Екипът ни ще се свърже с Вас при нужда от уточнение.</p>
        <NuxtLink to="/" class="mt-6 inline-flex rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white">Към началото</NuxtLink>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
const route = useRoute()
onMounted(() => {
  useAnalytics().purchase({
    order_number: route.query.order,
    value: Number(route.query.total || 0),
    currency: 'BGN',
  })
})
useSeo().page('Успешна поръчка', 'Поръчката е приета.', '/checkout/success')
</script>
