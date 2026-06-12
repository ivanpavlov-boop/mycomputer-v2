<template>
  <nav class="container-page py-4 text-sm text-slate-500">
    <ol class="flex flex-wrap items-center gap-2">
      <li><NuxtLink to="/" class="hover:text-brand-700">Начало</NuxtLink></li>
      <li v-for="item in items" :key="item.to || item.label" class="flex items-center gap-2">
        <span>/</span>
        <NuxtLink v-if="item.to" :to="item.to" class="hover:text-brand-700">{{ item.label }}</NuxtLink>
        <span v-else class="text-slate-900">{{ item.label }}</span>
      </li>
    </ol>
  </nav>
</template>

<script setup lang="ts">
defineProps<{ items: Array<{ label: string; to?: string }> }>()
</script>
