<template>
  <div class="container-page py-8">
    <div class="grid gap-6 lg:grid-cols-[260px_1fr]">
      <AccountSidebar />
      <section class="surface p-5">
        <h1 class="text-2xl font-bold">Фирмени данни</h1>
        <CompanyProfileForm v-if="company" class="mt-5" :company="company" @saved="load" />
        <EmptyState v-else title="Няма фирмен профил" />
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
const b2b = useB2B()
const company = ref<any>(null)
async function load() {
  const response = await b2b.company() as any
  company.value = response.data
}
onMounted(load)
useSeo().page('Фирмени данни', 'Управление на B2B фирмен профил.', '/account/b2b/company')
</script>
