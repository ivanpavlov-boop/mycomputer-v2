<template>
  <div class="container-page py-8">
    <div class="grid gap-8 md:grid-cols-[240px_1fr]">
      <AccountSidebar />
      <section class="space-y-5">
        <h1 class="text-2xl font-bold">Предпочитания</h1>
        <div class="rounded-md border border-slate-200 bg-white p-5">
          <div class="grid gap-4">
            <label class="flex items-center justify-between gap-4">
              <span>
                <span class="block font-semibold">Newsletter</span>
                <span class="text-sm text-slate-500">Новини, кампании и полезни продуктови предложения.</span>
              </span>
              <input v-model="preferences.newsletter" type="checkbox">
            </label>
            <label class="flex items-center justify-between gap-4">
              <span>
                <span class="block font-semibold">Промоции</span>
                <span class="text-sm text-slate-500">Известия за кампании и намаления.</span>
              </span>
              <input v-model="preferences.promotions" type="checkbox">
            </label>
            <label class="flex items-center justify-between gap-4">
              <span>
                <span class="block font-semibold">Продуктови новости</span>
                <span class="text-sm text-slate-500">Нови лаптопи, компоненти и аксесоари.</span>
              </span>
              <input v-model="preferences.product_updates" type="checkbox">
            </label>
            <label class="flex items-center justify-between gap-4">
              <span>
                <span class="block font-semibold">Наличности</span>
                <span class="text-sm text-slate-500">Архитектура за бъдещи back-in-stock известия.</span>
              </span>
              <input v-model="preferences.stock_alerts" type="checkbox">
            </label>
          </div>
          <div class="mt-5 flex gap-3">
            <BaseButton :loading="loading" @click="save">Запази</BaseButton>
            <BaseButton variant="secondary" @click="unsubscribe">Отписване</BaseButton>
          </div>
          <p v-if="message" class="mt-3 text-sm text-emerald-600">{{ message }}</p>
          <p v-if="error" class="mt-3 text-sm text-red-600">{{ error }}</p>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const auth = useAuthStore()
const api = useApi()
const loading = ref(false)
const message = ref('')
const error = ref('')
const preferences = reactive({
  newsletter: true,
  promotions: true,
  product_updates: true,
  stock_alerts: true,
})

async function save() {
  if (!auth.user?.email) return
  loading.value = true
  message.value = ''
  error.value = ''

  try {
    if (preferences.newsletter) {
      await api.post('/newsletter/subscribe', {
        email: auth.user.email,
        first_name: auth.user.first_name,
        last_name: auth.user.last_name,
        source: 'account',
        gdpr_consent: true,
      })
    } else {
      await api.post('/newsletter/unsubscribe', { email: auth.user.email })
    }
    message.value = 'Предпочитанията са запазени.'
  } catch {
    error.value = 'Не успяхме да запазим предпочитанията.'
  } finally {
    loading.value = false
  }
}

async function unsubscribe() {
  preferences.newsletter = false
  await save()
}

useSeo().page('Предпочитания', 'Email и продуктови предпочитания.', '/account/preferences')
</script>
