<template>
  <div class="container-page py-8">
    <div class="grid gap-6 lg:grid-cols-[260px_1fr]">
      <AccountSidebar />
      <section v-if="quote" class="surface p-5">
        <div class="flex items-center justify-between gap-4">
          <h1 class="text-2xl font-bold">{{ quote.quote_number }}</h1>
          <QuoteStatusBadge :status="quote.status" />
        </div>
        <div class="mt-5 grid gap-3">
          <div v-for="item in quote.items" :key="item.id" class="rounded-md border border-slate-200 p-3">
            <p class="font-semibold">{{ item.product_name }}</p>
            <p class="text-sm text-slate-600">Количество: {{ item.quantity }}</p>
            <p v-if="item.offered_price" class="text-sm font-semibold">Оферирана цена: {{ item.offered_price }} EUR</p>
          </div>
        </div>
        <QuoteActions class="mt-5" :quote="quote" @submit="submit" @accept="accept" />
        <QuoteMessages class="mt-6" :messages="quote.messages || []" />
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
const route = useRoute()
const b2b = useB2B()
const quote = ref<any>(null)
async function load() {
  const response = await b2b.quote(route.params.id as string) as any
  quote.value = response.data
}
async function submit() {
  await b2b.submitQuote(route.params.id as string)
  await load()
}
async function accept() {
  await b2b.acceptQuote(route.params.id as string)
  await load()
}
onMounted(load)
useSeo().page('B2B оферта', 'Детайли за B2B оферта.', `/account/b2b/quotes/${route.params.id}`)
</script>
