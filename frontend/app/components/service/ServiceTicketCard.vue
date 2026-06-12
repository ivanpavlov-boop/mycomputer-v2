<template>
  <NuxtLink :to="`/account/service/${ticket.id}`" class="surface block p-4 transition hover:border-brand-300">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <p class="text-xs font-semibold uppercase text-slate-500">{{ ticket.ticket_number }}</p>
        <h2 class="mt-1 text-lg font-bold">{{ ticket.subject }}</h2>
        <p class="mt-1 text-sm text-slate-600">{{ ticket.product?.name || 'Общ сервизен казус' }}</p>
      </div>
      <div class="flex flex-col items-end gap-2">
        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ statusLabel(ticket.status) }}</span>
        <WarrantyStatusBadge :warranty="ticket.warranty" />
      </div>
    </div>
  </NuxtLink>
</template>

<script setup lang="ts">
import type { ServiceTicket } from '~/types/api'

defineProps<{ ticket: ServiceTicket }>()

function statusLabel(status: string) {
  return status.replaceAll('_', ' ')
}
</script>
