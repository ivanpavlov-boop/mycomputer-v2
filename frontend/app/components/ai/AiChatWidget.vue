<template>
  <div>
    <button class="fixed bottom-5 right-5 z-50 rounded-full bg-brand-600 px-5 py-4 text-sm font-bold text-white shadow-lg" @click="open = true">
      AI помощник
    </button>
    <BaseModal :open="open" @close="open = false">
      <AiChatWindow />
    </BaseModal>
  </div>
</template>

<script setup lang="ts">
const open = ref(false)
</script>
