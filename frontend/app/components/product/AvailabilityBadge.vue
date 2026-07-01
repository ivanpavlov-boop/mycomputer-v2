<template>
  <button
    v-if="clickable"
    type="button"
    :class="badgeClass"
    @click="$emit('status-click', availability.code)"
  >
    <span v-if="icon" aria-hidden="true">{{ icon }}</span>
    <span>{{ displayName }}</span>
  </button>
  <span v-else :class="badgeClass">
    <span v-if="icon" aria-hidden="true">{{ icon }}</span>
    <span>{{ displayName }}</span>
  </span>
</template>

<script setup lang="ts">
import type { ProductAvailability } from '~/types/api'

const props = withDefaults(defineProps<{
  availability: ProductAvailability
  clickable?: boolean
}>(), {
  clickable: false,
})

defineEmits<{ 'status-click': [code: string] }>()

const iconMap: Record<string, string> = {
  check: '✓',
  warning: '!',
  clock: '◷',
  truck: '↦',
  package: '□',
}

const labelMap: Record<string, string> = {
  in_stock: 'В наличност',
  limited_stock: 'Ограничена наличност',
  limited: 'Ограничена наличност',
  out_of_stock: 'Няма наличност',
}

const toneMap: Record<string, { soft: string; solid: string; outline: string }> = {
  green: {
    soft: 'bg-emerald-50 text-emerald-700',
    solid: 'bg-emerald-600 text-white',
    outline: 'border border-emerald-300 text-emerald-700',
  },
  orange: {
    soft: 'bg-orange-50 text-orange-700',
    solid: 'bg-orange-600 text-white',
    outline: 'border border-orange-300 text-orange-700',
  },
  yellow: {
    soft: 'bg-yellow-50 text-yellow-800',
    solid: 'bg-yellow-500 text-slate-950',
    outline: 'border border-yellow-300 text-yellow-800',
  },
  red: {
    soft: 'bg-red-50 text-red-700',
    solid: 'bg-red-600 text-white',
    outline: 'border border-red-300 text-red-700',
  },
  blue: {
    soft: 'bg-blue-50 text-blue-700',
    solid: 'bg-blue-600 text-white',
    outline: 'border border-blue-300 text-blue-700',
  },
}

const icon = computed(() => iconMap[String(props.availability.icon || '')] || props.availability.icon)
const displayName = computed(() => {
  const code = String(props.availability.code || '')
  const name = String(props.availability.name || '')
  const normalizedName = name.toLowerCase().replace(/\s+/g, '_')

  return labelMap[code] || labelMap[normalizedName] || name
})
const style = computed(() => props.availability.badge_style || 'soft')
const tone = computed(() => {
  const color = String(props.availability.color || 'green')
  return toneMap[color]?.[style.value as 'soft' | 'solid' | 'outline'] || 'bg-slate-100 text-slate-700'
})
const badgeClass = computed(() => [
  'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold',
  props.clickable ? 'transition hover:opacity-80' : '',
  tone.value,
])
</script>
