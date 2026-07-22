<template>
  <div class="divide-y divide-slate-200">
    <fieldset v-for="filter in visibleFilters" :key="filter.key" class="min-w-0 py-4 first:pt-0 last:pb-0">
      <div class="flex items-start justify-between gap-3">
        <legend class="min-w-0 text-sm font-semibold leading-5 text-slate-900">
          {{ filter.label }}
        </legend>
        <button
          v-if="hasSelection(filter.key)"
          type="button"
          class="shrink-0 text-xs font-semibold text-brand-700 hover:text-brand-900 focus:outline-none focus:ring-2 focus:ring-brand-500"
          :aria-label="`Изчисти филтъра ${filter.label}`"
          @click="clearFilter(filter.key)"
        >
          Изчисти
        </button>
      </div>

      <CatalogDualRangeSlider
        v-if="resolvedControl(filter) === 'range_slider'"
        class="mt-3"
        :min="filter.min || 0"
        :max="filter.max || 0"
        :step="filter.step || 1"
        :model-min="selection[filter.key]?.min"
        :model-max="selection[filter.key]?.max"
        :label="filter.label"
        :unit="filter.unit"
        @commit="commitSlider(filter, $event)"
      />

      <div v-else-if="resolvedControl(filter) === 'min_max'" class="mt-3 grid grid-cols-2 gap-2">
        <label class="grid gap-1 text-xs text-slate-600">
          <span>От<span v-if="filter.unit"> ({{ filter.unit }})</span></span>
          <input
            type="number"
            :min="filter.min"
            :max="filter.max"
            :step="filter.step || 'any'"
            :value="draftValue(filter.key, 'min')"
            class="min-w-0 rounded-md border border-slate-300 bg-white px-2 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            :aria-label="`Минимална стойност за ${filter.label}`"
            @input="updateDraft(filter.key, 'min', $event)"
            @change="commitRange(filter, 'min', $event)"
            @blur="commitRange(filter, 'min', $event)"
            @keyup.enter="commitRange(filter, 'min', $event)"
          >
        </label>
        <label class="grid gap-1 text-xs text-slate-600">
          <span>До<span v-if="filter.unit"> ({{ filter.unit }})</span></span>
          <input
            type="number"
            :min="filter.min"
            :max="filter.max"
            :step="filter.step || 'any'"
            :value="draftValue(filter.key, 'max')"
            class="min-w-0 rounded-md border border-slate-300 bg-white px-2 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            :aria-label="`Максимална стойност за ${filter.label}`"
            @input="updateDraft(filter.key, 'max', $event)"
            @change="commitRange(filter, 'max', $event)"
            @blur="commitRange(filter, 'max', $event)"
            @keyup.enter="commitRange(filter, 'max', $event)"
          >
        </label>
      </div>

      <div v-else-if="resolvedControl(filter) === 'yes_no'" class="mt-2 grid gap-1" role="radiogroup" :aria-label="filter.label">
        <label
          v-for="option in filter.options || []"
          :key="option.key"
          class="flex min-w-0 cursor-pointer items-start gap-2 rounded px-1 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
        >
          <input
            type="radio"
            :name="`attribute-filter-${filter.key}`"
            class="mt-0.5 size-4 shrink-0 border-slate-300 text-brand-600 focus:ring-brand-500"
            :checked="selectedValues(filter.key).includes(option.key)"
            @change="selectBoolean(filter.key, option.key)"
          >
          <span class="min-w-0 break-words">{{ option.label }}</span>
        </label>
      </div>

      <div v-else-if="resolvedControl(filter) === 'options'" class="mt-2 grid max-h-56 gap-1 overflow-y-auto pr-1">
        <label
          v-for="option in filter.options || []"
          :key="option.key"
          class="flex min-w-0 cursor-pointer items-start gap-2 rounded px-1 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
        >
          <input
            type="checkbox"
            class="mt-0.5 size-4 shrink-0 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
            :checked="selectedValues(filter.key).includes(option.key)"
            @change="toggleOption(filter.key, option.key)"
          >
          <span class="min-w-0 break-words">
            {{ option.label }}<span v-if="typeof option.count === 'number'" class="text-slate-500"> ({{ option.count }})</span>
          </span>
        </label>
      </div>
    </fieldset>
  </div>
</template>

<script setup lang="ts">
import type { PublicProductAttributeFilter } from '~/types/api'
import type { AttributeFilterSelection, AttributeFilterSelections } from '~/utils/attributeFilters'
import { normalizedRangeSelection } from '~/utils/rangeValues'

const props = defineProps<{
  filters: PublicProductAttributeFilter[]
  selection: AttributeFilterSelections
}>()

const emit = defineEmits<{
  change: [key: string, selection: AttributeFilterSelection]
}>()

const drafts = reactive<Record<string, { min: string; max: string }>>({})
const lastCommitted = new Map<string, string>()
const visibleFilters = computed(() => props.filters.filter((filter) => resolvedControl(filter) !== null))

watch(
  () => [props.filters, props.selection] as const,
  () => {
    for (const filter of props.filters) {
      const selected = props.selection[filter.key] || {}

      drafts[filter.key] = {
        min: selected.min || '',
        max: selected.max || '',
      }
      lastCommitted.set(filter.key, JSON.stringify({ min: selected.min, max: selected.max }))
    }
  },
  { deep: true, immediate: true },
)

function selectedValues(key: string): string[] {
  return props.selection[key]?.values || []
}

function hasSelection(key: string): boolean {
  const selected = props.selection[key]

  return Boolean(selected?.values?.length || selected?.min || selected?.max)
}

function toggleOption(key: string, option: string) {
  const current = selectedValues(key)
  const values = current.includes(option)
    ? current.filter((value) => value !== option)
    : [...current, option]

  emit('change', key, { values })
}

function selectBoolean(key: string, option: string) {
  emit('change', key, { values: [option] })
}

function draftValue(key: string, bound: 'min' | 'max'): string {
  return drafts[key]?.[bound] || ''
}

function updateDraft(key: string, bound: 'min' | 'max', event: Event) {
  drafts[key] ||= { min: '', max: '' }
  drafts[key][bound] = (event.target as HTMLInputElement | null)?.value || ''
}

function commitRange(filter: PublicProductAttributeFilter, bound: 'min' | 'max', event: Event) {
  updateDraft(filter.key, bound, event)
  const draft = drafts[filter.key] || { min: '', max: '' }
  const availableMinimum = filter.min ?? Number.NEGATIVE_INFINITY
  const availableMaximum = filter.max ?? Number.POSITIVE_INFINITY
  let minimum = numericDraft(draft.min, availableMinimum, availableMaximum)
  let maximum = numericDraft(draft.max, availableMinimum, availableMaximum)

  if (minimum !== undefined && maximum !== undefined && minimum > maximum) {
    if (bound === 'min') {
      minimum = maximum
    } else {
      maximum = minimum
    }
  }

  const selection = {
    min: minimum === undefined ? undefined : String(minimum),
    max: maximum === undefined ? undefined : String(maximum),
  }
  const signature = JSON.stringify(selection)

  drafts[filter.key] = { min: selection.min || '', max: selection.max || '' }

  if (lastCommitted.get(filter.key) !== signature) {
    lastCommitted.set(filter.key, signature)
    emit('change', filter.key, selection)
  }
}

function commitSlider(filter: PublicProductAttributeFilter, range: { minimum: number; maximum: number }) {
  emit('change', filter.key, normalizedRangeSelection(filter.min || 0, filter.max || 0, range.minimum, range.maximum))
}

function numericDraft(value: string, minimum: number, maximum: number): number | undefined {
  if (!value.trim()) {
    return undefined
  }

  const number = Number(value)

  return Number.isFinite(number) ? Math.min(maximum, Math.max(minimum, number)) : undefined
}

function resolvedControl(filter: PublicProductAttributeFilter): PublicProductAttributeFilter['control'] | null {
  if (filter.type === 'number_range') {
    return ['range_slider', 'min_max'].includes(filter.control) ? filter.control : 'min_max'
  }

  if (filter.type === 'boolean') {
    return 'yes_no'
  }

  if (filter.type === 'select' || filter.type === 'multiselect') {
    return 'options'
  }

  return null
}

function clearFilter(key: string) {
  emit('change', key, {})
}
</script>
