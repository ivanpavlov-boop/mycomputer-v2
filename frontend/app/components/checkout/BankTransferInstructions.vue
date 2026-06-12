<template>
  <div class="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
    <p class="font-semibold">Банков превод</p>
    <p class="mt-1">{{ instructions || 'Ще получите банкови данни и основание за плащане в потвърждението.' }}</p>
  </div>
</template>

<script setup lang="ts">
defineProps<{ instructions?: string | null }>()
</script>
