<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 bg-slate-950/40" @click="$emit('close')" />
    <div v-if="open" class="fixed inset-x-4 top-20 z-50 mx-auto max-w-lg rounded-lg bg-white p-5 shadow-soft">
      <slot />
    </div>
  </Teleport>
</template>

<script setup lang="ts">
defineProps<{ open: boolean }>()
defineEmits<{ close: [] }>()
</script>
