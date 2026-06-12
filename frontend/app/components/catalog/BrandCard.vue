<template>
  <NuxtLink :to="`/brand/${brand.slug}`" class="surface flex min-h-24 items-center justify-center p-4 text-center font-semibold hover:border-brand-200 hover:text-brand-700">
    {{ brand.name }}
  </NuxtLink>
</template>

<script setup lang="ts">
import type { Brand } from '~/types/api'

defineProps<{ brand: Brand }>()
</script>
