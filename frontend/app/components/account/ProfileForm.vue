<template>
  <form class="surface space-y-4 p-5" @submit.prevent="submit">
    <h2 class="text-xl font-bold">Профил</h2>
    <div class="grid gap-4 md:grid-cols-2">
      <BaseInput v-model="form.first_name" placeholder="Име" />
      <BaseInput v-model="form.last_name" placeholder="Фамилия" />
      <BaseInput v-model="form.phone" placeholder="Телефон" />
      <BaseInput v-model="form.company_name" placeholder="Фирма" />
      <BaseInput v-model="form.vat_number" placeholder="ДДС номер" />
    </div>
    <p v-if="message" class="text-sm text-green-700">{{ message }}</p>
    <BaseButton type="submit">Запази</BaseButton>
  </form>
</template>

<script setup lang="ts">
const api = useApi()
const auth = useAuthStore()
const form = reactive({
  first_name: auth.user?.first_name || '',
  last_name: auth.user?.last_name || '',
  phone: auth.user?.phone || '',
  company_name: auth.user?.company_name || '',
  vat_number: auth.user?.vat_number || '',
})
const message = ref('')

async function submit() {
  const response = await api.patch<{ data: typeof auth.user }>('/auth/profile', form)
  auth.user = response.data
  message.value = 'Профилът е обновен.'
}
</script>
