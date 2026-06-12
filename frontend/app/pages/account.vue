<template>
  <div class="container-page py-8">
    <div class="grid gap-6 lg:grid-cols-[260px_1fr]">
      <AccountSidebar />
      <section class="surface p-5">
        <h1 class="text-2xl font-bold">Моят профил</h1>
        <div v-if="account" class="mt-4 grid gap-4 md:grid-cols-3">
          <div class="rounded-md border border-slate-200 p-4">
            <p class="text-sm text-slate-500">Име</p>
            <p class="font-semibold">{{ account.profile.name }}</p>
          </div>
          <div class="rounded-md border border-slate-200 p-4">
            <p class="text-sm text-slate-500">Поръчки</p>
            <p class="font-semibold">{{ account.orders_summary.total_orders }}</p>
          </div>
          <div class="rounded-md border border-slate-200 p-4">
            <p class="text-sm text-slate-500">Любими</p>
            <p class="font-semibold">{{ account.wishlist_summary.items_count }}</p>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
const auth = useAuthStore()
const api = useApi()
const router = useRouter()
const account = ref<any>(null)

onMounted(async () => {
  await auth.fetchUser()
  if (!auth.isAuthenticated) {
    await router.push('/login')
    return
  }
  const response = await api.get<{ data: any }>('/account')
  account.value = response.data
})

useSeo().page('Моят профил', 'Клиентски профил.', '/account')
</script>
