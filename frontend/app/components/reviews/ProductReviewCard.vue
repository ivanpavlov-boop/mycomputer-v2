<template>
  <article class="surface grid gap-3 p-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <div class="flex items-center gap-2">
          <ProductRatingStars :rating="review.rating" />
          <span v-if="review.is_verified_purchase" class="rounded bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">Потвърдена покупка</span>
        </div>
        <h3 v-if="review.title" class="mt-2 font-semibold">{{ review.title }}</h3>
        <p class="text-sm text-slate-500">{{ review.customer_name }}</p>
      </div>
      <span class="text-sm text-slate-500">{{ new Date(review.created_at).toLocaleDateString('bg-BG') }}</span>
    </div>
    <p class="text-slate-700">{{ review.comment }}</p>
    <div v-if="review.pros || review.cons" class="grid gap-2 text-sm md:grid-cols-2">
      <p v-if="review.pros"><strong>Плюсове:</strong> {{ review.pros }}</p>
      <p v-if="review.cons"><strong>Минуси:</strong> {{ review.cons }}</p>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-3">
      <ProductReviewVoteButtons @vote="$emit('vote', review.id, $event)" />
      <button class="text-sm font-semibold text-red-700" @click="showReport = !showReport">Сигнализирай</button>
    </div>
    <ProductReviewReportModal v-if="showReport" @submit="$emit('report', review.id, $event)" />
  </article>
</template>

<script setup lang="ts">
import type { ProductReview } from '~/types/api'

defineProps<{ review: ProductReview }>()
defineEmits<{ vote: [number, 'helpful' | 'not_helpful'], report: [number, { reason: string, message?: string }] }>()
const showReport = ref(false)
</script>
