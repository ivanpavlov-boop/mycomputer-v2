<template>
  <div class="container-page py-8">
    <div class="grid gap-6 lg:grid-cols-[260px_1fr]">
      <AccountSidebar />
      <section class="surface p-5">
        <div class="flex items-center justify-between gap-4">
          <h1 class="text-2xl font-bold">Заявки за оферти</h1>
          <NuxtLink to="/account/b2b/quotes/new" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white">Нова заявка</NuxtLink>
        </div>
        <div class="mt-5 grid gap-3">
          <NuxtLink v-for="quote in quotes" :key="quote.id" :to="`/account/b2b/quotes/${quote.id}`" class="rounded-md border border-slate-200 p-4">
            <div class="flex items-center justify-between gap-3">
              <span class="font-semibold">{{ quote.quote_number }}</span>
              <QuoteStatusBadge :status="quote.status" />
            </div>
            <p class="mt-1 text-sm text-slate-600">{{ quote.company_name || quote.customer_name }}</p>
          </NuxtLink>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
const b2b = useB2B()
const quotes = ref<any[]>([])
onMounted(async () => {
  const response = await b2b.quotes() as any
  quotes.value = response.data || []
})
useSeo().page('Заявки за оферти', 'B2B заявки за оферти.', '/account/b2b/quotes')
</script>
