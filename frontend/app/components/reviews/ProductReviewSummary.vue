<template>
  <div class="surface grid gap-4 p-4 md:grid-cols-[180px_1fr]">
    <div>
      <p class="text-4xl font-bold">{{ summary.average_rating.toFixed(1) }}</p>
      <ProductRatingStars :rating="summary.average_rating" />
      <p class="mt-1 text-sm text-slate-500">{{ summary.total_reviews }} ревюта</p>
      <p class="text-sm text-slate-500">{{ summary.verified_reviews_count }} потвърдени покупки</p>
    </div>
    <RatingDistribution :distribution="summary.rating_distribution" :total="summary.total_reviews" />
  </div>
</template>

<script setup lang="ts">
import type { ProductReviewSummary } from '~/types/api'

defineProps<{ summary: ProductReviewSummary }>()
</script>
