<template>
  <section class="surface p-5">
    <div class="flex flex-wrap items-end gap-3">
      <label class="grid gap-1 text-sm font-semibold text-slate-700">
        Компонент
        <BaseSelect v-model="componentType">
          <option v-for="option in typeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
        </BaseSelect>
      </label>
      <label class="grid min-w-64 flex-1 gap-1 text-sm font-semibold text-slate-700">
        Търсене
        <BaseInput v-model="search" placeholder="RTX 5070, AM5, DDR5..." />
      </label>
      <BaseButton @click="loadProducts">Търси</BaseButton>
    </div>

    <div class="mt-4 grid gap-3 md:grid-cols-2">
      <article v-for="product in products" :key="product.id" class="rounded-md border border-slate-200 p-3">
        <NuxtLink class="font-semibold hover:text-brand-700" :to="`/p/${product.slug}`">{{ product.name }}</NuxtLink>
        <div class="mt-2 flex items-center justify-between gap-3">
          <span class="font-bold text-brand-700">{{ Number(product.promo_price || product.price).toFixed(2) }} лв.</span>
          <BaseButton @click="$emit('select', product.id, componentType)">Избери</BaseButton>
        </div>
      </article>
    </div>
    <EmptyState v-if="searched && !products.length" title="Няма резултати" text="Пробвайте с друг модел, марка или характеристика." />
  </section>
</template>

<script setup lang="ts">
import type { ApiCollection, PcComponentType, ProductCard } from '~/types/api'

const props = defineProps<{ componentTypes: PcComponentType[] }>()
defineEmits<{ select: [productId: number, componentType: PcComponentType] }>()

const api = useApi()
const search = ref('')
const searched = ref(false)
const componentType = ref<PcComponentType>(props.componentTypes[0] || 'cpu')
const products = ref<ProductCard[]>([])
const typeOptions = computed(() => props.componentTypes.map((type) => ({ label: type, value: type })))

async function loadProducts() {
  searched.value = true
  const response = await api.get<{ data: { products: ApiCollection<ProductCard> } }>('/search', {
    q: search.value || componentType.value,
    per_page: 8,
  })
  products.value = response.data.products.data
}
</script>
