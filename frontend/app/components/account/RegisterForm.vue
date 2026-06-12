<template>
  <form class="surface mx-auto max-w-2xl space-y-4 p-6" @submit.prevent="submit">
    <h1 class="text-2xl font-bold">Регистрация</h1>
    <div class="grid gap-4 md:grid-cols-2">
      <BaseInput v-model="form.first_name" placeholder="Име" />
      <BaseInput v-model="form.last_name" placeholder="Фамилия" />
      <BaseInput v-model="form.email" type="email" placeholder="Имейл" />
      <BaseInput v-model="form.phone" placeholder="Телефон" />
      <BaseInput v-model="form.company_name" placeholder="Фирма" />
      <BaseInput v-model="form.vat_number" placeholder="ДДС номер" />
      <BaseInput v-model="form.password" type="password" placeholder="Парола" />
      <BaseInput v-model="form.password_confirmation" type="password" placeholder="Повтори паролата" />
    </div>
    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
    <BaseButton type="submit">Създай профил</BaseButton>
  </form>
</template>

<script setup lang="ts">
const auth = useAuthStore()
const router = useRouter()
const form = reactive({
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  company_name: '',
  vat_number: '',
  password: '',
  password_confirmation: '',
})
const error = ref('')

async function submit() {
  error.value = ''
  try {
    await auth.register(form)
    await router.push('/account')
  } catch {
    error.value = 'Провери въведените данни и паролата.'
  }
}
</script>
