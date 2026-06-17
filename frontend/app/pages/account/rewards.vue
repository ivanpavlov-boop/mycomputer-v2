<template>
  <div class="container-page py-8">
    <div class="grid gap-8 md:grid-cols-[240px_1fr]">
      <AccountSidebar />
      <section class="space-y-5">
        <h1 class="text-2xl font-bold">Награди</h1>
        <LoadingState v-if="pending" />
        <ErrorState v-else-if="error" title="Не успяхме да заредим наградите." />
        <div v-else class="grid gap-4 md:grid-cols-2">
          <article v-for="reward in rewards" :key="reward.id" class="rounded-md border border-slate-200 bg-white p-5">
            <div class="flex items-start justify-between gap-4">
              <div>
                <h2 class="font-bold">{{ reward.title }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ reward.points_cost }} точки</p>
              </div>
              <span class="rounded bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-700">{{ reward.discount_value }} {{ reward.discount_type === 'percentage' ? '%' : 'EUR' }}</span>
            </div>
            <BaseButton class="mt-4 w-full" @click="redeem(reward.id)">Осребри</BaseButton>
          </article>
        </div>
        <p v-if="message" class="text-sm text-emerald-600">{{ message }}</p>
        <p v-if="redeemError" class="text-sm text-red-600">{{ redeemError }}</p>
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

interface Reward {
  id: number
  title: string
  points_cost: number
  discount_type: string
  discount_value: string
}

const api = useApi()
const message = ref('')
const redeemError = ref('')
const { data, pending, error, refresh } = await useAsyncData('account-rewards', () => api.get<{ data: Reward[] }>('/rewards'))
const rewards = computed(() => data.value?.data || [])

async function redeem(id: number) {
  message.value = ''
  redeemError.value = ''
  try {
    const response = await api.post<{ data: { code: string } }>('/rewards/redeem', { reward_id: id })
    message.value = `Кодът ${response.data.code} е активиран за вашия профил.`
    await refresh()
  } catch {
    redeemError.value = 'Наградата не може да бъде осребрена.'
  }
}

useSeo().page('Награди', 'Каталог с награди и ваучери.', '/account/rewards')
</script>
