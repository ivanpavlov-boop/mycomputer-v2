<template>
  <div>
    <Breadcrumbs :items="[{ label: 'Сравнение' }]" />
    <div class="container-page">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-3xl font-bold">Сравнение на продукти</h1>
        <BaseButton v-if="compare.products.length" variant="secondary" @click="compare.clear()">Изчисти</BaseButton>
      </div>
      <LoadingState v-if="compare.loading" class="mt-6" />
      <EmptyState v-else-if="compare.products.length < 2" class="mt-6" title="Изберете поне два продукта" text="Използвайте бутона „Сравни“ в продуктовите карти." />
      <div v-else class="mt-6">
        <BaseButton @click="loadCompare">Обнови сравнението</BaseButton>
        <div v-if="comparison" class="mt-6 overflow-x-auto surface">
          <table class="w-full min-w-[720px] text-sm">
            <thead>
              <tr class="border-b bg-slate-100 text-left">
                <th class="p-3">Показател</th>
                <th v-for="product in comparison.products" :key="product.id" class="p-3">
                  <div class="flex items-start justify-between gap-2">
                    <span>{{ product.name }}</span>
                    <button class="text-xs font-semibold text-red-600" @click="compare.remove(product.id)">Премахни</button>
                  </div>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr class="border-b">
                <td class="p-3 font-semibold">Цена</td>
                <td v-for="product in comparison.products" :key="product.id" class="p-3">{{ comparison.prices[product.id] }} EUR</td>
              </tr>
              <tr class="border-b">
                <td class="p-3 font-semibold">Наличност</td>
                <td v-for="product in comparison.products" :key="product.id" class="p-3">{{ comparison.stock_statuses[product.id] }}</td>
              </tr>
              <tr v-for="(values, name) in comparison.shared_attributes" :key="`shared-${name}`" class="border-b bg-emerald-50">
                <td class="p-3 font-semibold">{{ name }}</td>
                <td v-for="product in comparison.products" :key="product.id" class="p-3">{{ values }}</td>
              </tr>
              <tr v-for="(values, name) in comparison.differences" :key="name" class="border-b">
                <td class="p-3 font-semibold">{{ name }}</td>
                <td v-for="product in comparison.products" :key="product.id" class="p-3">{{ values[product.id] || '-' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { ProductCard } from '~/types/api'

const api = useApi()
const compare = useCompareStore()
const seo = useSeo()
const comparison = ref<null | {
  products: ProductCard[]
  shared_attributes: Record<string, string>
  differences: Record<string, Record<number, string>>
  prices: Record<number, string | number>
  stock_statuses: Record<number, string>
}>(null)

async function loadCompare() {
  const response = await api.post<{ data: typeof comparison.value }>('/compare', { product_ids: compare.products.map((product) => product.id) })
  comparison.value = response.data
}

await compare.load()
if (compare.products.length >= 2) await loadCompare()
seo.page('Сравнение', 'Сравнете избрани продукти.', '/compare')
</script>
