<template>
  <div v-if="product">
    <Breadcrumbs :items="[
      { label: product.category?.name || 'Категория', to: product.category ? `/c/${product.category.slug}` : undefined },
      { label: product.name },
    ]" />
    <section class="container-page grid gap-8 lg:grid-cols-[1fr_1fr]">
      <ProductGallery :images="product.images" :product-name="product.name" />
      <div class="space-y-5">
        <div>
          <p v-if="product.brand" class="text-sm font-semibold text-brand-700">{{ product.brand.name }}</p>
          <h1 class="mt-2 text-3xl font-bold tracking-normal">{{ product.name }}</h1>
          <div class="mt-3 flex flex-wrap gap-3 text-sm text-slate-500">
            <span>SKU: {{ product.sku }}</span>
            <span v-if="product.ean">EAN: {{ product.ean }}</span>
          </div>
        </div>
        <ProductAvailabilityPanel
          v-if="product.availability"
          :availability="product.availability"
          :product-id="product.id"
          :quantity="product.quantity"
        />
        <StockBadge v-else :status="product.stock_status" />
        <ProductPrice :product="product" />
        <div class="grid gap-3 sm:grid-cols-2">
          <BaseButton :disabled="product.availability?.allow_purchase === false" @click="addToCart">
            {{ product.availability?.allow_purchase === false ? 'Не е наличен' : 'Добави в количката' }}
          </BaseButton>
          <BaseButton variant="secondary" @click="compare.toggle(product)">
            {{ compare.has(product.id) ? 'Премахни от сравнение' : 'Сравни' }}
          </BaseButton>
          <BaseButton variant="secondary" @click="wishlist.toggle(product.id)">
            {{ wishlist.has(product.id) ? 'Премахни от любими' : 'Добави в любими' }}
          </BaseButton>
          <RequestQuoteButton @request="requestQuote" />
        </div>
        <div class="surface grid gap-3 p-4 text-sm text-slate-700">
          <p>Лизинг: очаквайте калкулатор за месечни вноски.</p>
          <p>Гаранция: {{ product.warranty_months || 24 }} месеца</p>
        </div>
        <p v-if="product.short_description" class="text-slate-700">{{ product.short_description }}</p>
      </div>
    </section>
    <section class="container-page mt-10">
      <ProductTabs :description="product.description" :attributes="product.attributes" />
    </section>
    <section class="container-page mt-10">
      <ProductReviewList :product-slug="slug" />
    </section>
    <RelatedProducts :products="product.related_products" />
    <AccessoryProducts :products="product.accessory_products" />
  </div>
</template>

<script setup lang="ts">
const route = useRoute()
const products = useProducts()
const seo = useSeo()
const cart = useCartStore()
const compare = useCompareStore()
const wishlist = useWishlistStore()
const analytics = useAnalytics()
const b2b = useB2B()
const router = useRouter()
const auth = useAuthStore()
const slug = String(route.params.slug)
const { data } = await useAsyncData(`product-${slug}`, () => products.detail(slug))
const product = computed(() => data.value?.data)

watchEffect(() => {
  if (!product.value) return

  seo.product(product.value)
  analytics.viewItem({ product_id: product.value.id, sku: product.value.sku, value: Number(product.value.promo_price || product.value.price) })
  analytics.viewContent({ product_id: product.value.id, sku: product.value.sku, content_name: product.value.name })

  if (product.value.availability?.code === 'out_of_stock') {
    analytics.productOutOfStockView({ product_id: product.value.id, sku: product.value.sku })
  } else if (product.value.availability?.code === 'preorder') {
    analytics.productPreorderView({ product_id: product.value.id, sku: product.value.sku })
  } else if (product.value.availability?.code === 'incoming') {
    analytics.productIncomingView({ product_id: product.value.id, sku: product.value.sku })
  }
})

function addToCart() {
  if (!product.value || product.value.availability?.allow_purchase === false) return
  cart.add(product.value)
}

async function requestQuote() {
  await auth.fetchUser()
  if (!auth.isAuthenticated) {
    await router.push('/login')
    return
  }
  const response = await b2b.requestProductQuote(slug, { quantity: 1, notes: 'Заявка за B2B оферта от продуктова страница' }) as any
  await router.push(`/account/b2b/quotes/${response.data.id}`)
}
</script>
