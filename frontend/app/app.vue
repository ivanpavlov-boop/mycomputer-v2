<template>
  <NuxtLayout>
    <NuxtPage />
    <AiChatWidget v-if="showAiChatWidget" />
  </NuxtLayout>
</template>

<script setup lang="ts">
const route = useRoute()
const showAiChatWidget = computed(() => route.path !== '/catalog' && route.path !== '/categories' && !route.path.startsWith('/c/'))
</script>
