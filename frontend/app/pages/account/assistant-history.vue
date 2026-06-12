<template>
  <div>
    <Breadcrumbs :items="[{ label: 'Профил', to: '/account' }, { label: 'AI история' }]" />
    <div class="container-page grid gap-8 lg:grid-cols-[260px_1fr]">
      <AccountSidebar />
      <section>
        <h1 class="text-2xl font-bold">AI история</h1>
        <AiConversationList class="mt-6" :conversations="items" />
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const ai = useAiAssistant()
const { data } = await useAsyncData('account-ai-conversations', () => ai.conversations())
const items = computed(() => data.value?.data || [])
useSeo().page('AI история', 'Вашите разговори с продуктовия асистент.', '/account/assistant-history')
</script>
