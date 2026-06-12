<template>
  <section class="surface p-5">
    <h2 class="text-lg font-bold">Препоръки</h2>
    <div v-if="missing.length" class="mt-3 rounded-md bg-slate-50 p-3 text-sm">
      Липсващи основни компоненти: <span class="font-semibold">{{ missing.join(', ') }}</span>
    </div>
    <div v-if="suggested.length" class="mt-4 grid gap-3 md:grid-cols-2">
      <ProductCard v-for="product in suggested" :key="product.id" :product="product" />
    </div>
    <p v-if="!missing.length && !suggested.length" class="mt-3 text-sm text-slate-500">Конфигурацията изглежда пълна. Проверете съвместимостта преди поръчка.</p>
  </section>
</template>

<script setup lang="ts">
import type { PcComponentType, ProductCard } from '~/types/api'

defineProps<{ missing: PcComponentType[]; suggested: ProductCard[] }>()
</script>
