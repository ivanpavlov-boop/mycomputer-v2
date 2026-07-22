<template>
  <section class="py-4 first:pt-0" aria-labelledby="public-price-filter-title">
    <div class="flex items-start justify-between gap-3">
      <h3 id="public-price-filter-title" class="text-sm font-semibold text-slate-900">{{ filter.label }}</h3>
      <button
        v-if="hasSelection"
        type="button"
        class="shrink-0 text-xs font-semibold text-brand-700 hover:text-brand-900 focus:outline-none focus:ring-2 focus:ring-brand-500"
        aria-label="Изчисти филтъра Цена"
        @click="$emit('clear')"
      >
        Изчисти
      </button>
    </div>
    <CatalogDualRangeSlider
      class="mt-3"
      :min="filter.min"
      :max="filter.max"
      :step="filter.step"
      :model-min="selection.min"
      :model-max="selection.max"
      :label="filter.label"
      :currency="filter.currency"
      @commit="commit"
    />
  </section>
</template>

<script setup lang="ts">
import type { PublicProductPriceFilter } from '~/types/api'
import type { PriceFilterSelection } from '~/utils/priceFilters'
import { normalizedRangeSelection } from '~/utils/rangeValues'

const props = defineProps<{
  filter: PublicProductPriceFilter
  selection: PriceFilterSelection
}>()

const emit = defineEmits<{
  change: [selection: PriceFilterSelection]
  clear: []
}>()

const hasSelection = computed(() => Boolean(props.selection.min || props.selection.max))

function commit(range: { minimum: number; maximum: number }) {
  emit('change', normalizedRangeSelection(props.filter.min, props.filter.max, range.minimum, range.maximum))
}
</script>
