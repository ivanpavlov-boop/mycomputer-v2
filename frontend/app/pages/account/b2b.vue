<template>
  <div class="container-page py-8">
    <div class="grid gap-6 lg:grid-cols-[260px_1fr]">
      <AccountSidebar />
      <section class="surface p-5">
        <h1 class="text-2xl font-bold">B2B профил</h1>
        <div v-if="status?.has_company" class="mt-4">
          <p class="font-semibold">{{ status.company.name }}</p>
          <p class="text-sm text-slate-600">Статус: {{ status.company.approval_status }}</p>
        </div>
        <NuxtLink v-else to="/b2b/apply" class="mt-4 inline-flex rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white">Кандидатствай</NuxtLink>
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
const b2b = useB2B()
const status = ref<any>(null)
onMounted(async () => {
  const response = await b2b.status() as any
  status.value = response.data
})
useSeo().page('B2B профил', 'Фирмен B2B профил.', '/account/b2b')
</script>
