<template>
  <form class="surface mx-auto max-w-md space-y-4 p-6" @submit.prevent="submit">
    <h1 class="text-2xl font-bold">Нова парола</h1>
    <BaseInput v-model="form.email" type="email" placeholder="Имейл" />
    <BaseInput v-model="form.password" type="password" placeholder="Нова парола" />
    <BaseInput v-model="form.password_confirmation" type="password" placeholder="Повтори новата парола" />
    <p v-if="message" class="text-sm text-green-700">{{ message }}</p>
    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
    <BaseButton type="submit" class="w-full">Смени паролата</BaseButton>
  </form>
</template>

<script setup lang="ts">
const route = useRoute()
const config = useRuntimeConfig()
const form = reactive({
  email: String(route.query.email || ''),
  token: String(route.query.token || ''),
  password: '',
  password_confirmation: '',
})
const message = ref('')
const error = ref('')

async function submit() {
  message.value = ''
  error.value = ''

  try {
    await $fetch('/auth/reset-password', {
      baseURL: config.public.apiBaseUrl,
      method: 'POST',
      body: form,
    })
    message.value = 'Паролата е сменена успешно. Можеш да влезеш с новата парола.'
  } catch {
    error.value = 'Линкът е невалиден или паролата не отговаря на изискванията.'
  }
}
</script>
