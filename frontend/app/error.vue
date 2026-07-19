<template>
  <NuxtLayout>
    <main class="container-page py-16">
      <UiErrorState
        :title="statusCode === 404 ? t('errors.notFoundTitle') : t('errors.genericTitle')"
        :text="statusCode === 404 ? t('errors.notFoundBody') : t('errors.genericBody')"
      />
      <NuxtLink
        :to="localePath('/')"
        class="mt-6 inline-flex rounded-md bg-brand-700 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-800"
      >
        {{ t('common.backToHome') }}
      </NuxtLink>
    </main>
  </NuxtLayout>
</template>

<script setup lang="ts">
const props = defineProps<{ error: { statusCode?: number } }>()
const { t } = useI18n()
const localePath = useLocalePath()
const statusCode = computed(() => props.error.statusCode ?? 500)
</script>
