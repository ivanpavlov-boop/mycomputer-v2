<template>
  <div class="container-page py-8">
    <div class="grid gap-8 md:grid-cols-[240px_1fr]">
      <AccountSidebar />
      <section class="space-y-5">
        <h1 class="text-2xl font-bold">Лоялна програма</h1>
        <LoadingState v-if="pending" />
        <ErrorState v-else-if="error" title="Не успяхме да заредим лоялния профил." />
        <div v-else-if="loyalty" class="grid gap-4">
          <div class="rounded-md border border-slate-200 bg-white p-5">
            <div class="flex flex-wrap items-center justify-between gap-4">
              <div>
                <p class="text-sm uppercase text-slate-500">Текущо ниво</p>
                <p class="text-3xl font-bold capitalize">{{ loyalty.tier }}</p>
              </div>
              <div class="text-right">
                <p class="text-sm text-slate-500">Баланс</p>
                <p class="text-3xl font-bold">{{ loyalty.points_balance }} т.</p>
              </div>
            </div>
            <div v-if="loyalty.next_tier" class="mt-5">
              <div class="mb-2 flex justify-between text-sm text-slate-600">
                <span>До {{ loyalty.next_tier.tier }}</span>
                <span>{{ loyalty.next_tier.remaining_points }} т.</span>
              </div>
              <div class="h-2 rounded-full bg-slate-100">
                <div class="h-2 rounded-full bg-brand-600" :style="{ width: `${loyalty.next_tier.progress_percentage}%` }" />
              </div>
            </div>
          </div>
          <div class="rounded-md border border-slate-200 bg-white p-5">
            <h2 class="font-bold">Последни движения</h2>
            <div class="mt-3 divide-y divide-slate-100">
              <div v-for="transaction in loyalty.recent_transactions" :key="transaction.id" class="flex justify-between py-3 text-sm">
                <span>{{ transaction.description }}</span>
                <span :class="transaction.points > 0 ? 'text-emerald-600' : 'text-red-600'">{{ transaction.points }} т.</span>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

interface LoyaltyResponse {
  data: {
    tier: string
    points_balance: number
    lifetime_points: number
    next_tier: null | { tier: string, remaining_points: number, progress_percentage: number }
    recent_transactions: Array<{ id: number, points: number, description: string }>
  }
}

const api = useApi()
const { data, pending, error } = await useAsyncData('account-loyalty', () => api.get<LoyaltyResponse>('/account/loyalty'))
const loyalty = computed(() => data.value?.data)

useSeo().page('Лоялна програма', 'Точки, нива и награди.', '/account/loyalty')
</script>
