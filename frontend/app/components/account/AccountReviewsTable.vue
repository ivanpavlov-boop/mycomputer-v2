<template>
  <div class="surface overflow-x-auto">
    <table class="w-full min-w-[720px] text-sm">
      <thead>
        <tr class="border-b bg-slate-100 text-left">
          <th class="p-3">Продукт</th>
          <th class="p-3">Оценка</th>
          <th class="p-3">Статус</th>
          <th class="p-3">Дата</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="review in reviews" :key="review.id" class="border-b">
          <td class="p-3">{{ review.product?.name || 'Продукт' }}</td>
          <td class="p-3"><ProductRatingStars :rating="review.rating" /></td>
          <td class="p-3">{{ review.status }}</td>
          <td class="p-3">{{ new Date(review.created_at).toLocaleDateString('bg-BG') }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
import type { ProductReview } from '~/types/api'

defineProps<{ reviews: ProductReview[] }>()
</script>
