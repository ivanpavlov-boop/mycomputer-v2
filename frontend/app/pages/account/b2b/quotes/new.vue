<template>
  <div class="container-page py-8">
    <div class="grid gap-6 lg:grid-cols-[260px_1fr]">
      <AccountSidebar />
      <section class="surface p-5">
        <h1 class="text-2xl font-bold">Нова заявка за оферта</h1>
        <QuoteRequestForm class="mt-5" @created="goToQuote" />
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
const router = useRouter()
function goToQuote(quote: any) {
  router.push(`/account/b2b/quotes/${quote.id}`)
}
useSeo().page('Нова B2B оферта', 'Създаване на B2B заявка за оферта.', '/account/b2b/quotes/new')
</script>
