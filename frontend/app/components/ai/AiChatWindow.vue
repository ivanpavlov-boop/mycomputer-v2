<template>
  <div class="surface flex max-h-[680px] flex-col overflow-hidden">
    <header class="border-b p-4">
      <h2 class="font-bold">AI продуктов асистент</h2>
      <p class="text-sm text-slate-500">Попитайте за лаптоп, монитор, принтер или сравнение.</p>
    </header>
    <div class="flex-1 space-y-3 overflow-y-auto p-4">
      <div v-for="message in messages" :key="message.id || message.content" :class="message.role === 'user' ? 'ml-auto bg-brand-600 text-white' : 'mr-auto bg-slate-100 text-slate-900'" class="max-w-[85%] rounded-md p-3 text-sm">
        {{ message.content }}
      </div>
      <div v-if="recommendation?.products?.length" class="grid gap-3">
        <p class="text-sm font-semibold">{{ recommendation.summary }}</p>
        <AiRecommendationCard v-for="product in recommendation.products" :key="product.id" :product="product" />
      </div>
    </div>
    <form class="flex gap-2 border-t p-4" @submit.prevent="send">
      <BaseInput v-model="input" placeholder="Напр. лаптоп за AutoCAD до 3000 лв." />
      <BaseButton type="submit">Изпрати</BaseButton>
    </form>
  </div>
</template>

<script setup lang="ts">
import type { AiMessage, AiRecommendation } from '~/types/api'

const ai = useAiAssistant()
const input = ref('')
const conversationId = ref<number | undefined>()
const messages = ref<AiMessage[]>([])
const recommendation = ref<AiRecommendation | null>(null)
const analytics = useAnalytics()

async function send() {
  if (!input.value.trim()) return
  const text = input.value.trim()
  input.value = ''
  if (!conversationId.value) await analytics.aiConversationStart({ query: text })
  const [chatResponse, searchResponse] = await Promise.all([
    ai.chat(text, conversationId.value),
    ai.search(text),
  ])
  conversationId.value = chatResponse.data.id
  messages.value = chatResponse.data.messages
  recommendation.value = searchResponse.data
}
</script>
