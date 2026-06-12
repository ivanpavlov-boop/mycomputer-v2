<template>
  <div class="container-page py-8">
    <div class="grid gap-6 lg:grid-cols-[260px_1fr]">
      <AccountSidebar />
      <main>
        <div class="flex items-center justify-between gap-4">
          <div>
            <h1 class="text-2xl font-bold">Сервиз и гаранция</h1>
            <p class="mt-1 text-sm text-slate-600">Следете гаранционни рекламации, сервизни заявки и връщания.</p>
          </div>
          <NuxtLink class="inline-flex rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700" to="/account/service/new">Нова заявка</NuxtLink>
        </div>
        <div class="mt-6 grid gap-4">
          <ServiceTicketCard v-for="ticket in tickets" :key="ticket.id" :ticket="ticket" />
          <EmptyState v-if="!tickets.length" title="Няма сервизни заявки" description="Създайте нова заявка, ако имате нужда от съдействие." />
        </div>
      </main>
    </div>
  </div>
</template>

<script setup lang="ts">
const auth = useAuthStore()
const router = useRouter()
const service = useServiceTickets()

onMounted(async () => {
  await auth.fetchUser()
  if (!auth.isAuthenticated) await router.push('/login')
})

const { data } = await useAsyncData('service-tickets', () => service.list())
const tickets = computed(() => data.value?.data || [])

useSeo().page('Сервиз и гаранция', 'Вашите сервизни заявки и гаранционни рекламации.', '/account/service')
</script>
