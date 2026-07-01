<template>
  <ProductAvailabilityBadge :availability="fallbackAvailability" />
</template>

<script setup lang="ts">
const props = defineProps<{ status: string }>()

const labels: Record<string, string> = {
  in_stock: 'В наличност',
  limited_stock: 'Ограничена наличност',
  limited: 'Ограничена наличност',
  incoming: 'Очаква се',
  out_of_stock: 'Няма наличност',
  preorder: 'Предварителна поръчка',
  on_request: 'По заявка',
  discontinued: 'Спрян продукт',
}

const colors: Record<string, string> = {
  in_stock: 'green',
  limited_stock: 'orange',
  limited: 'orange',
  incoming: 'blue',
  out_of_stock: 'red',
  preorder: 'blue',
  on_request: 'yellow',
  discontinued: 'red',
}

const fallbackAvailability = computed(() => ({
  code: props.status,
  name: labels[props.status] || props.status,
  color: colors[props.status] || 'green',
  badge_style: 'soft',
  allow_purchase: !['out_of_stock', 'discontinued'].includes(props.status),
}))
</script>
