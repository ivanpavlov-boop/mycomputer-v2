<template>
  <div class="space-y-1 text-sm text-slate-600">
    <p v-if="availability.message">{{ availability.message }}</p>
    <p v-if="availability.expected_date">
      Очаквана дата: <span class="font-medium text-slate-800">{{ formattedDate }}</span>
    </p>
    <p v-if="availability.supplier_lead_time_days">
      Срок за доставка от доставчик:
      <span class="font-medium text-slate-800">{{ availability.supplier_lead_time_days }} дни</span>
    </p>
    <p v-if="availability.show_stock_quantity && typeof quantity === 'number'">
      Налични бройки: <span class="font-medium text-slate-800">{{ quantity }}</span>
    </p>
  </div>
</template>

<script setup lang="ts">
import type { ProductAvailability } from '~/types/api'

const props = defineProps<{
  availability: ProductAvailability
  quantity?: number
}>()

const formattedDate = computed(() => {
  if (!props.availability.expected_date) return ''

  return new Intl.DateTimeFormat('bg-BG', { dateStyle: 'medium' }).format(new Date(props.availability.expected_date))
})
</script>
