<template>
  <section class="grid gap-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="text-2xl font-bold">Ревюта</h2>
      <BaseSelect v-model="sort" @update:model-value="load">
        <option v-for="option in sortOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
      </BaseSelect>
    </div>
    <ProductReviewSummary v-if="summary" :summary="summary" />
    <ProductReviewForm :product-slug="productSlug" @submitted="load" />
    <EmptyState v-if="!items.length" title="Все още няма одобрени ревюта" text="Бъдете първият клиент, който ще остави мнение." />
    <ProductReviewCard v-for="review in items" :key="review.id" :review="review" @vote="vote" @report="report" />
  </section>
</template>

<script setup lang="ts">
import type { ProductReview, ProductReviewSummary } from '~/types/api'

const props = defineProps<{ productSlug: string }>()
const reviews = useReviews()
const items = ref<ProductReview[]>([])
const summary = ref<ProductReviewSummary | null>(null)
const sort = ref('newest')
const sortOptions = [
  { label: 'Най-нови', value: 'newest' },
  { label: 'Най-стари', value: 'oldest' },
  { label: 'Най-висока оценка', value: 'highest_rating' },
  { label: 'Най-ниска оценка', value: 'lowest_rating' },
  { label: 'Най-полезни', value: 'most_helpful' },
]

async function load() {
  const response = await reviews.list(props.productSlug, { sort: sort.value })
  items.value = response.data
  summary.value = response.summary
}

async function vote(reviewId: number, voteType: 'helpful' | 'not_helpful') {
  await reviews.vote(reviewId, voteType)
  await load()
}

async function report(reviewId: number, payload: { reason: string, message?: string }) {
  await reviews.report(reviewId, payload)
}

await load()
</script>
