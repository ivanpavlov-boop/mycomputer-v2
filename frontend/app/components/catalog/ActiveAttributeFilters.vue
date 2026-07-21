<template>
  <div v-if="filters.length" class="mb-4 flex flex-wrap items-center gap-2" aria-label="Активни филтри по характеристики">
    <template v-for="filter in filters" :key="filter.key">
      <button
        v-if="filter.type === 'number_range'"
        type="button"
        class="inline-flex max-w-full items-center gap-2 rounded-full border border-brand-200 bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-900 focus:outline-none focus:ring-2 focus:ring-brand-500"
        :aria-label="`Премахни филтъра ${filter.label}`"
        @click="$emit('remove', filter.key)"
      >
        <span class="truncate">{{ numericLabel(filter) }}</span>
        <span aria-hidden="true">x</span>
      </button>
      <button
        v-for="value in filter.values || []"
        v-else
        :key="`${filter.key}-${value.key}`"
        type="button"
        class="inline-flex max-w-full items-center gap-2 rounded-full border border-brand-200 bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-900 focus:outline-none focus:ring-2 focus:ring-brand-500"
        :aria-label="`Премахни ${filter.label}: ${value.label}`"
        @click="$emit('remove', filter.key, value.key)"
      >
        <span class="truncate">{{ filter.label }}: {{ value.label }}</span>
        <span aria-hidden="true">x</span>
      </button>
    </template>

    <button
      type="button"
      class="px-2 py-1 text-xs font-semibold text-brand-700 hover:text-brand-900 focus:outline-none focus:ring-2 focus:ring-brand-500"
      @click="$emit('clear-all')"
    >
      Изчисти всички
    </button>
  </div>
</template>

<script setup lang="ts">
import type { PublicProductActiveAttributeFilter } from '~/types/api'

defineProps<{ filters: PublicProductActiveAttributeFilter[] }>()
defineEmits<{
  remove: [key: string, value?: string]
  'clear-all': []
}>()

function numericLabel(filter: PublicProductActiveAttributeFilter): string {
  const unit = filter.unit ? ` ${filter.unit}` : ''

  if (filter.min !== null && filter.min !== undefined && filter.max !== null && filter.max !== undefined) {
    return `${filter.label}: ${filter.min}-${filter.max}${unit}`
  }

  if (filter.min !== null && filter.min !== undefined) {
    return `${filter.label}: от ${filter.min}${unit}`
  }

  return `${filter.label}: до ${filter.max}${unit}`
}
</script>
