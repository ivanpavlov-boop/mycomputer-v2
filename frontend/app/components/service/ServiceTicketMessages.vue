<template>
  <div class="surface p-4">
    <h2 class="text-lg font-bold">Съобщения</h2>
    <div class="mt-4 space-y-3">
      <div v-for="message in messages" :key="message.id" class="rounded-md bg-slate-50 p-3 text-sm">
        <p class="font-semibold">{{ message.author || 'Клиент' }}</p>
        <p class="mt-1 whitespace-pre-wrap text-slate-700">{{ message.message }}</p>
      </div>
    </div>
    <form class="mt-4 flex gap-2" @submit.prevent="submit">
      <BaseInput v-model="text" placeholder="Напишете съобщение" />
      <BaseButton type="submit">Изпрати</BaseButton>
    </form>
  </div>
</template>

<script setup lang="ts">
import type { ServiceTicketMessage } from '~/types/api'

defineProps<{ messages: ServiceTicketMessage[] }>()
const emit = defineEmits<{ send: [message: string] }>()
const text = ref('')

function submit() {
  if (!text.value.trim()) return
  emit('send', text.value)
  text.value = ''
}
</script>
