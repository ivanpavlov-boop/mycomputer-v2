<template>
  <SeoPageContent :content="page.blocks" />
</template>

<script setup lang="ts">
import type { ContentPage } from '~/types/api'

defineProps<{ page: ContentPage }>()
</script>
