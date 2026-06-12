<template>
  <form class="surface mx-auto max-w-md space-y-4 p-6" @submit.prevent="submit">
    <h1 class="text-2xl font-bold">Забравена парола</h1>
    <BaseInput v-model="email" type="email" placeholder="Имейл" />
    <p v-if="message" class="text-sm text-green-700">{{ message }}</p>
    <BaseButton type="submit" class="w-full">Изпрати линк</BaseButton>
  </form>
</template>

<script setup lang="ts">
const auth = useAuthStore()
const email = ref('')
const message = ref('')

async function submit() {
  await auth.forgotPassword(email.value)
  message.value = 'Ако имейлът съществува, ще получиш инструкции.'
}
</script>
