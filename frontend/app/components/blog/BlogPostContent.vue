<template>
  <article class="prose max-w-none" v-html="content" />
</template>

<script setup lang="ts">
defineProps<{ content: string }>()
</script>
