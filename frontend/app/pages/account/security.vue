<template>
  <div class="container-page py-8">
    <div class="grid gap-6 lg:grid-cols-[260px_1fr]">
      <AccountSidebar />
      <form class="surface space-y-4 p-5" @submit.prevent="submit">
        <h1 class="text-2xl font-bold">Сигурност</h1>
        <BaseInput v-model="form.current_password" type="password" placeholder="Текуща парола" />
        <BaseInput v-model="form.password" type="password" placeholder="Нова парола" />
        <BaseInput v-model="form.password_confirmation" type="password" placeholder="Повтори новата парола" />
        <p v-if="message" class="text-sm text-green-700">{{ message }}</p>
        <BaseButton type="submit">Смени паролата</BaseButton>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
const auth = useAuthStore()
const api = useApi()
const router = useRouter()
const form = reactive({ current_password: '', password: '', password_confirmation: '' })
const message = ref('')

onMounted(async () => {
  await auth.fetchUser()
  if (!auth.isAuthenticated) await router.push('/login')
})

async function submit() {
  await api.patch('/auth/password', form)
  message.value = 'Паролата е сменена. Влез отново.'
  await auth.logout()
}

useSeo().page('Сигурност', 'Настройки за сигурност.', '/account/security')
</script>
