<template>
  <div>
    <Breadcrumbs :items="[{ label: 'Профил', to: '/account' }, { label: 'Моите ревюта' }]" />
    <div class="container-page grid gap-8 lg:grid-cols-[260px_1fr]">
      <AccountSidebar />
      <section>
        <h1 class="text-2xl font-bold">Моите ревюта</h1>
        <AccountReviewsTable class="mt-6" :reviews="items" />
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { ProductReview } from '~/types/api'

definePageMeta({ middleware: 'auth' })

const reviews = useReviews()
const response = await reviews.account()
const items = computed<ProductReview[]>(() => response.data)
useSeo().page('Моите ревюта', 'Вашите изпратени продуктови ревюта.', '/account/reviews')
</script>
