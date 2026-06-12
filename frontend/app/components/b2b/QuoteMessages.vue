<template>
  <div class="grid gap-3">
    <div v-for="message in messages" :key="message.id" class="rounded-md border border-slate-200 p-3">
      <p class="text-sm text-slate-500">{{ message.sender_type }}</p>
      <p>{{ message.message }}</p>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{ messages: any[] }>()
</script>
