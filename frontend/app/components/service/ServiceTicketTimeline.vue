<template>
  <div class="surface p-4">
    <h2 class="text-lg font-bold">Статус</h2>
    <ol class="mt-4 space-y-3 text-sm">
      <li v-for="step in steps" :key="step" class="flex items-center gap-3">
        <span class="h-2.5 w-2.5 rounded-full" :class="step === ticket.status ? 'bg-brand-600' : 'bg-slate-300'" />
        <span :class="step === ticket.status ? 'font-semibold text-brand-700' : 'text-slate-600'">{{ step.replaceAll('_', ' ') }}</span>
      </li>
    </ol>
  </div>
</template>

<script setup lang="ts">
import type { ServiceTicket } from '~/types/api'

defineProps<{ ticket: ServiceTicket }>()

const steps = ['new', 'awaiting_review', 'approved', 'awaiting_service', 'in_diagnosis', 'awaiting_parts', 'repaired', 'completed', 'closed']
</script>
