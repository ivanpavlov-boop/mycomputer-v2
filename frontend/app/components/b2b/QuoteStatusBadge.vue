<template>
  <span class="inline-flex rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">{{ label }}</span>
</template>

<script setup lang="ts">
const props = defineProps<{ status: string }>()
const labels: Record<string, string> = {
  draft: 'Чернова',
  submitted: 'Изпратена',
  under_review: 'В преглед',
  offered: 'Оферта',
  accepted: 'Приета',
  rejected: 'Отказана',
  expired: 'Изтекла',
  converted: 'Поръчка',
}
const label = computed(() => labels[props.status] || props.status)
</script>
