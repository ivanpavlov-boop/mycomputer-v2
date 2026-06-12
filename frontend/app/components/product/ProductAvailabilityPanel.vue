<template>
  <section class="surface space-y-3 p-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <AvailabilityBadge
        :availability="availability"
        clickable
        @status-click="analytics.availabilityStatusClick({ product_id: productId, status: $event })"
      />
      <span v-if="availability.allow_purchase" class="text-xs font-semibold text-emerald-700">Може да се поръча</span>
      <span v-else class="text-xs font-semibold text-red-700">Не може да се поръча</span>
    </div>
    <AvailabilityInfo :availability="availability" :quantity="quantity" />
  </section>
</template>

<script setup lang="ts">
import type { ProductAvailability } from '~/types/api'

defineProps<{
  availability: ProductAvailability
  productId: number
  quantity?: number
}>()

const analytics = useAnalytics()
</script>
