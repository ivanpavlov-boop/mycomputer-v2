<template>
  <div>
    <Breadcrumbs :items="[{ label: 'PC конфигуратор', to: '/pc-builder' }, { label: buildData?.name || 'Конфигурация' }]" />
    <main class="container-page py-8">
      <LoadingState v-if="loading" />
      <div v-else-if="buildData && metaData && compatibilityData" class="grid gap-6 xl:grid-cols-[1fr_380px]">
        <div class="grid gap-6">
          <BuildSummary :build="buildData" :component-types="metaData.component_types" @remove-item="removeBuildItem" />
          <ComponentSelector :component-types="metaData.component_types" @select="addComponent" />
          <BuildRecommendations :missing="missingComponents" :suggested="suggestedProducts" />
        </div>
        <aside class="grid h-fit gap-6">
          <BuildPriceBox :total="buildData.total_price" />
          <CompatibilityPanel :compatibility="compatibilityData" />
          <section class="surface grid gap-3 p-5">
            <BaseButton @click="saveBuild">Запази</BaseButton>
            <BaseButton variant="secondary" @click="addBuildToCart">Добави в количката</BaseButton>
            <BaseButton variant="ghost" @click="duplicateBuild">Дублирай</BaseButton>
          </section>
        </aside>
      </div>
      <ErrorState v-else title="Конфигурацията не е намерена" text="Проверете дали използвате правилната сесия или профил." />
    </main>
  </div>
</template>

<script setup lang="ts">
import type { PcBuild, PcBuilderMeta, PcCompatibility, PcComponentType, ProductCard } from '~/types/api'

const route = useRoute()
const router = useRouter()
const pcBuilder = usePcBuilder()
const cart = useCartStore()
const analytics = useAnalytics()

const loading = ref(true)
const buildData = ref<PcBuild | null>(null)
const metaData = ref<PcBuilderMeta | null>(null)
const compatibilityData = ref<PcCompatibility | null>(null)
const missingComponents = ref<PcComponentType[]>([])
const suggestedProducts = ref<ProductCard[]>([])

onMounted(load)

async function load() {
  loading.value = true
  const id = String(route.params.id)
  const [metaResponse, buildResponse, compatibilityResponse, recommendationResponse] = await Promise.all([
    pcBuilder.meta(),
    pcBuilder.build(id),
    pcBuilder.compatibility(id),
    pcBuilder.recommendations(id),
  ])
  metaData.value = metaResponse.data
  buildData.value = buildResponse.data
  compatibilityData.value = compatibilityResponse.data
  missingComponents.value = recommendationResponse.data.missing_components
  suggestedProducts.value = recommendationResponse.data.suggested_products as ProductCard[]
  loading.value = false
}

async function addComponent(productId: number, componentType: PcComponentType) {
  if (!buildData.value) return
  buildData.value = (await pcBuilder.addItem(buildData.value.id, productId, componentType)).data
  await load()
}

async function removeBuildItem(itemId: number) {
  if (!buildData.value) return
  buildData.value = (await pcBuilder.removeItem(buildData.value.id, itemId)).data
  await load()
}

async function saveBuild() {
  if (!buildData.value) return
  buildData.value = (await pcBuilder.update(buildData.value.id, { status: 'saved' })).data
  await analytics.builderSave({ build_id: buildData.value.id, total: buildData.value.total_price })
}

async function duplicateBuild() {
  if (!buildData.value) return
  const duplicate = await pcBuilder.create({ name: `${buildData.value.name} - копие`, description: buildData.value.description || undefined })
  for (const item of buildData.value.items) {
    await pcBuilder.addItem(duplicate.data.id, item.product.id, item.component_type, item.quantity)
  }
  await router.push(`/pc-builder/build/${duplicate.data.id}`)
}

async function addBuildToCart() {
  if (!buildData.value) return
  const response = await pcBuilder.addToCart(buildData.value.id)
  cart.backendCart = response.data
  await analytics.builderComplete({ build_id: buildData.value.id, total: buildData.value.total_price })
  await router.push('/cart')
}

useSeo().page('PC конфигурация', 'Редакция и проверка на съвместимост на PC конфигурация.', `/pc-builder/build/${route.params.id}`)
</script>
