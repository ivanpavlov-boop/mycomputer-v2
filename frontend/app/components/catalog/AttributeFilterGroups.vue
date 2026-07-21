<template>
  <div class="divide-y divide-slate-200">
    <fieldset v-for="filter in filters" :key="filter.key" class="min-w-0 py-4 first:pt-0 last:pb-0">
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

      <div v-if="filter.type === 'number_range'" class="mt-3 grid grid-cols-2 gap-2">
        <label class="grid gap-1 text-xs text-slate-600">
          <span>От<span v-if="filter.unit"> ({{ filter.unit }})</span></span>
          <input
            type="number"
            :min="filter.min"
            :max="filter.max"
            :step="filter.step || 'any'"
            :value="selection[filter.key]?.min || ''"
            class="min-w-0 rounded-md border border-slate-300 bg-white px-2 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            :aria-label="`Минимална стойност за ${filter.label}`"
            @change="updateRange(filter.key, 'min', $event)"
          >
        </label>
        <label class="grid gap-1 text-xs text-slate-600">
          <span>До<span v-if="filter.unit"> ({{ filter.unit }})</span></span>
          <input
            type="number"
            :min="filter.min"
            :max="filter.max"
            :step="filter.step || 'any'"
            :value="selection[filter.key]?.max || ''"
            class="min-w-0 rounded-md border border-slate-300 bg-white px-2 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            :aria-label="`Максимална стойност за ${filter.label}`"
            @change="updateRange(filter.key, 'max', $event)"
          >
        </label>
      </div>

      <div v-else class="mt-2 grid max-h-56 gap-1 overflow-y-auto pr-1">
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

const props = defineProps<{
  filters: PublicProductAttributeFilter[]
  selection: AttributeFilterSelections
}>()

const emit = defineEmits<{
  change: [key: string, selection: AttributeFilterSelection]
}>()

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

function updateRange(key: string, bound: 'min' | 'max', event: Event) {
  const value = (event.target as HTMLInputElement | null)?.value.trim() || undefined
  emit('change', key, { ...props.selection[key], [bound]: value })
}

function clearFilter(key: string) {
  emit('change', key, {})
}
</script>
