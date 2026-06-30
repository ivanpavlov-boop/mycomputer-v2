<template>
  <UiBaseSelect :model-value="modelValue" @update:model-value="$emit('update:modelValue', $event)">
    <option value="newest">Най-нови</option>
    <option value="price_asc">Цена възходящо</option>
    <option value="price_desc">Цена низходящо</option>
    <option value="bestseller">Най-продавани</option>
    <option value="featured">Препоръчани</option>
    <option value="name_asc">Име А-Я</option>
    <option value="name_desc">Име Я-А</option>
  </UiBaseSelect>
</template>

<script setup lang="ts">
defineProps<{ modelValue?: string }>()
defineEmits<{ 'update:modelValue': [value: string] }>()
</script>
