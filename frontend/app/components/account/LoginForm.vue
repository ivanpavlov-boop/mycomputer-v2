<template>
  <form class="surface mx-auto max-w-md space-y-4 p-6" @submit.prevent="submit">
    <h1 class="text-2xl font-bold">Вход</h1>
    <BaseInput v-model="form.email" type="email" placeholder="Имейл" />
    <BaseInput v-model="form.password" type="password" placeholder="Парола" />
    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
    <BaseButton type="submit" class="w-full">Вход</BaseButton>
  </form>
</template>

<script setup lang="ts">
const auth = useAuthStore()
const router = useRouter()
const form = reactive({ email: '', password: '' })
const error = ref('')

async function submit() {
  error.value = ''
  try {
    await auth.login(form)
    await router.push('/account')
  } catch {
    error.value = 'Невалиден имейл или парола.'
  }
}
</script>
