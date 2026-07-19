<template>
  <NuxtLayout>
    <NuxtPage />
    <AiChatWidget v-if="showAiChatWidget" />
  </NuxtLayout>
</template>

<script setup lang="ts">
const isReadOnlyStorefrontRoute = useReadOnlyStorefrontRoute()
const showAiChatWidget = computed(() => !isReadOnlyStorefrontRoute.value)
const { locale } = useI18n()

useHead(() => ({
  htmlAttrs: {
    lang: locale.value,
  },
}))
</script>
