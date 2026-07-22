<template>
  <div v-if="hasSelection" class="mb-4 flex flex-wrap items-center gap-2" aria-label="Активен ценови филтър">
    <button
      type="button"
      class="inline-flex max-w-full items-center gap-2 rounded-full border border-brand-200 bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-900 focus:outline-none focus:ring-2 focus:ring-brand-500"
      aria-label="Премахни ценовия филтър"
      @click="$emit('clear')"
    >
      <span class="truncate">{{ label }}</span>
      <span aria-hidden="true">x</span>
    </button>
  </div>
</template>

<script setup lang="ts">
import type { PublicProductPriceFilter } from '~/types/api'
import type { PriceFilterSelection } from '~/utils/priceFilters'

const props = defineProps<{
  filter: PublicProductPriceFilter | null
  selection: PriceFilterSelection
}>()

defineEmits<{ clear: [] }>()

const hasSelection = computed(() => Boolean(props.selection.min || props.selection.max))
const label = computed(() => {
  const formatter = new Intl.NumberFormat('bg-BG', { style: 'currency', currency: props.filter?.currency || 'EUR' })

  if (props.selection.min && props.selection.max) {
    return `Цена: ${formatter.format(Number(props.selection.min))} – ${formatter.format(Number(props.selection.max))}`
  }

  if (props.selection.min) {
    return `Цена: от ${formatter.format(Number(props.selection.min))}`
  }

  return `Цена: до ${formatter.format(Number(props.selection.max))}`
})
</script>
