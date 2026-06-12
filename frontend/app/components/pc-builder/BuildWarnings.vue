<template>
  <div v-if="errors.length || warnings.length" class="grid gap-2">
    <div v-for="error in errors" :key="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
      {{ error }}
    </div>
    <div v-for="warning in warnings" :key="warning" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
      {{ warning }}
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{ errors: string[]; warnings: string[] }>()
</script>
