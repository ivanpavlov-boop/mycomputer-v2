<template>
  <div class="grid gap-3">
    <article v-for="conversation in conversations" :key="conversation.id" class="surface p-4">
      <NuxtLink :to="`/assistant?conversation=${conversation.id}`" class="font-semibold hover:text-brand-700">
        {{ conversation.title || 'AI разговор' }}
      </NuxtLink>
      <p class="mt-1 text-sm text-slate-500">{{ conversation.messages?.length || 0 }} съобщения</p>
    </article>
  </div>
</template>

<script setup lang="ts">
import type { AiConversation } from '~/types/api'

defineProps<{ conversations: AiConversation[] }>()
</script>
