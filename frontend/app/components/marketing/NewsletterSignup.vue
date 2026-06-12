<template>
  <form class="space-y-3" @submit.prevent="submit">
    <label class="text-sm font-semibold text-white" for="newsletter-email">Абонамент за оферти</label>
    <div class="flex gap-2">
      <input
        id="newsletter-email"
        v-model="email"
        class="min-w-0 flex-1 rounded-md border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-white outline-none focus:border-brand-400"
        type="email"
        placeholder="email@example.com"
        required
      >
      <button class="rounded-md bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700" type="submit" :disabled="loading">
        Запиши ме
      </button>
    </div>
    <label class="flex items-start gap-2 text-xs text-slate-400">
      <input v-model="consent" class="mt-1" type="checkbox" required>
      <span>Съгласен съм да получавам новини, оферти и продуктови известия.</span>
    </label>
    <p v-if="message" class="text-xs text-emerald-300">{{ message }}</p>
    <p v-if="error" class="text-xs text-red-300">{{ error }}</p>
  </form>
</template>

<script setup lang="ts">
const api = useApi()
const email = ref('')
const consent = ref(false)
const loading = ref(false)
const message = ref('')
const error = ref('')

async function submit() {
  loading.value = true
  message.value = ''
  error.value = ''

  try {
    await api.post('/newsletter/subscribe', {
      email: email.value,
      source: 'newsletter',
      gdpr_consent: consent.value,
    })
    message.value = 'Абонаментът е активиран.'
  } catch {
    error.value = 'Абонаментът не беше записан. Проверете имейла и съгласието.'
  } finally {
    loading.value = false
  }
}
</script>
