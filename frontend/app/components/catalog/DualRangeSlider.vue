<template>
  <div class="min-w-0">
    <div class="flex items-center justify-between gap-3 text-xs font-semibold text-slate-700">
      <span>{{ formattedValue(localMinimum) }}</span>
      <span>{{ formattedValue(localMaximum) }}</span>
    </div>

    <div class="relative mt-2 h-10 min-w-0" :aria-label="label">
      <div class="absolute inset-x-0 top-1/2 h-1.5 -translate-y-1/2 rounded-full bg-slate-200" />
      <div
        class="absolute top-1/2 h-1.5 -translate-y-1/2 rounded-full bg-brand-600"
        :style="selectedTrackStyle"
      />
      <input
        class="dual-range absolute inset-0 h-10 w-full"
        type="range"
        :min="min"
        :max="max"
        :step="step"
        :value="localMinimum"
        :disabled="disabled"
        :aria-label="`Минимална стойност за ${label}`"
        @input="updateMinimum"
        @change="commit"
      >
      <input
        class="dual-range absolute inset-0 h-10 w-full"
        type="range"
        :min="min"
        :max="max"
        :step="step"
        :value="localMaximum"
        :disabled="disabled"
        :aria-label="`Максимална стойност за ${label}`"
        @input="updateMaximum"
        @change="commit"
      >
    </div>
  </div>
</template>

<script setup lang="ts">
import { boundedRange } from '~/utils/rangeValues'

const props = withDefaults(defineProps<{
  min: number
  max: number
  step: number
  modelMin?: string | number | null
  modelMax?: string | number | null
  label: string
  unit?: string | null
  currency?: string | null
  disabled?: boolean
}>(), {
  modelMin: null,
  modelMax: null,
  unit: null,
  currency: null,
  disabled: false,
})

const emit = defineEmits<{
  'update:modelMin': [value: number]
  'update:modelMax': [value: number]
  commit: [range: { minimum: number; maximum: number }]
}>()

const initial = boundedRange(props.min, props.max, props.step, props.modelMin, props.modelMax)
const localMinimum = ref(initial.minimum)
const localMaximum = ref(initial.maximum)

watch(
  () => [props.min, props.max, props.step, props.modelMin, props.modelMax] as const,
  () => {
    const next = boundedRange(props.min, props.max, props.step, props.modelMin, props.modelMax)
    localMinimum.value = next.minimum
    localMaximum.value = next.maximum
  },
)

const selectedTrackStyle = computed(() => {
  const span = props.max - props.min
  const left = span > 0 ? ((localMinimum.value - props.min) / span) * 100 : 0
  const right = span > 0 ? ((localMaximum.value - props.min) / span) * 100 : 100

  return { left: `${left}%`, width: `${Math.max(0, right - left)}%` }
})

function updateMinimum(event: Event) {
  const value = Math.min(Number((event.target as HTMLInputElement).value), localMaximum.value)
  localMinimum.value = value
  emit('update:modelMin', value)
}

function updateMaximum(event: Event) {
  const value = Math.max(Number((event.target as HTMLInputElement).value), localMinimum.value)
  localMaximum.value = value
  emit('update:modelMax', value)
}

function commit() {
  emit('commit', { minimum: localMinimum.value, maximum: localMaximum.value })
}

function formattedValue(value: number): string {
  if (props.currency) {
    return new Intl.NumberFormat('bg-BG', {
      style: 'currency',
      currency: props.currency,
      maximumFractionDigits: 2,
    }).format(value)
  }

  return `${value}${props.unit ? ` ${props.unit}` : ''}`
}
</script>

<style scoped>
.dual-range {
  appearance: none;
  background: transparent;
  pointer-events: none;
}

.dual-range::-webkit-slider-thumb {
  appearance: none;
  width: 32px;
  height: 32px;
  border: 2px solid #2563eb;
  border-radius: 9999px;
  background: #ffffff;
  box-shadow: 0 1px 3px rgb(15 23 42 / 20%);
  cursor: pointer;
  pointer-events: auto;
}

.dual-range::-moz-range-thumb {
  width: 32px;
  height: 32px;
  border: 2px solid #2563eb;
  border-radius: 9999px;
  background: #ffffff;
  box-shadow: 0 1px 3px rgb(15 23 42 / 20%);
  cursor: pointer;
  pointer-events: auto;
}

.dual-range:focus-visible::-webkit-slider-thumb {
  outline: 2px solid #2563eb;
  outline-offset: 2px;
}

.dual-range:focus-visible::-moz-range-thumb {
  outline: 2px solid #2563eb;
  outline-offset: 2px;
}
</style>
