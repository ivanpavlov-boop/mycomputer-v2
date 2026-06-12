<template>
  <article v-if="isHtmlContent" class="prose max-w-none" v-html="content" />
  <div v-else class="space-y-8">
    <section
      v-for="block in visibleBlocks"
      :key="block.id"
      class="cms-block"
      :class="[visibilityClasses(block), alignmentClass(block), spacingClass(block)]"
      :style="blockStyle(block)"
    >
      <div :class="containerClasses(block)">
        <template v-if="block.type === 'rich_text'">
          <div class="prose max-w-none" v-html="block.data.body" />
        </template>

        <template v-else-if="block.type === 'custom_html'">
          <div v-html="block.data.html || block.data.body" />
        </template>

        <template v-else-if="['hero', 'brand_campaign'].includes(block.type)">
          <div class="relative overflow-hidden rounded-md bg-slate-950 text-white" :style="{ minHeight: 'var(--cms-height)' }">
            <ContentCmsResponsivePicture
              v-if="hasResponsiveImage(block)"
              class="absolute inset-0 h-full w-full"
              :images="block.images"
              :alt="block.data.heading || ''"
            />
            <div class="relative z-10 flex h-full min-h-[inherit] items-center bg-slate-950/45 p-6 md:p-10">
              <div class="max-w-2xl">
                <p v-if="block.data.subtitle" :class="subtitleClass(block)" class="font-semibold text-amber-300">{{ block.data.subtitle }}</p>
                <h2 v-if="block.data.heading" :class="headingClass(block)" class="mt-2 font-extrabold tracking-normal">{{ block.data.heading }}</h2>
                <p v-if="block.data.text" :class="textClass(block)" class="mt-4 text-white/90">{{ block.data.text }}</p>
                <div v-if="block.data.button_label && block.data.button_url" :class="buttonGroupClass(block)" class="mt-6">
                  <NuxtLink :to="block.data.button_url" :class="buttonClass(block)" class="rounded-md bg-brand-600 px-5 py-3 text-sm font-semibold text-white hover:bg-brand-700">
                    {{ block.data.button_label }}
                  </NuxtLink>
                </div>
              </div>
            </div>
          </div>
        </template>

        <template v-else-if="block.type === 'image_text'">
          <div class="grid items-center gap-6 md:grid-cols-2" :class="orderClass(block)">
            <ContentCmsResponsivePicture
              v-if="hasResponsiveImage(block)"
              class="overflow-hidden rounded-md"
              :images="block.images"
              :alt="block.data.heading || ''"
            />
            <div>
              <p v-if="block.data.subtitle" :class="subtitleClass(block)" class="font-semibold text-brand-700">{{ block.data.subtitle }}</p>
              <h2 v-if="block.data.heading" :class="headingClass(block)" class="mt-2 font-bold tracking-normal">{{ block.data.heading }}</h2>
              <div v-if="block.data.body" :class="textClass(block)" class="prose mt-4 max-w-none" v-html="block.data.body" />
            </div>
          </div>
        </template>

        <template v-else-if="block.type === 'products_grid'">
          <h2 v-if="block.data.heading" :class="headingClass(block)" class="font-bold tracking-normal">{{ block.data.heading }}</h2>
          <p v-if="block.data.text" :class="textClass(block)" class="mt-2 text-slate-600">{{ block.data.text }}</p>
          <div class="mt-5 grid" :class="[gridClass(block), spacingClass(block)]">
            <ProductCard v-for="product in productsForBlock(block)" :key="product.id" :product="product" />
          </div>
        </template>

        <template v-else-if="block.type === 'categories_grid'">
          <h2 v-if="block.data.heading" :class="headingClass(block)" class="font-bold tracking-normal">{{ block.data.heading }}</h2>
          <p v-if="block.data.text" :class="textClass(block)" class="mt-2 text-slate-600">{{ block.data.text }}</p>
          <div class="mt-5 grid" :class="[gridClass(block), spacingClass(block)]">
            <CategoryCard v-for="category in categoriesForBlock(block)" :key="category.id" :category="category" />
          </div>
        </template>

        <template v-else-if="['bundle_grid', 'bundle_carousel'].includes(block.type)">
          <h2 v-if="block.data.heading" :class="headingClass(block)" class="font-bold tracking-normal">{{ block.data.heading }}</h2>
          <div class="mt-5 grid" :class="[gridClass(block), spacingClass(block)]">
            <BundleCard v-for="bundle in block.resolved?.bundles || []" :key="bundle.id" :bundle="bundle" />
          </div>
        </template>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import type { Category, CmsBlock, ProductCard } from '~/types/api'

const props = withDefaults(defineProps<{
  content: string | CmsBlock[]
  relatedProducts?: ProductCard[]
  relatedCategories?: Category[]
}>(), {
  relatedProducts: () => [],
  relatedCategories: () => [],
})

const isHtmlContent = computed(() => typeof props.content === 'string')
const blocks = computed(() => Array.isArray(props.content) ? props.content : [])
const visibleBlocks = computed(() => blocks.value.filter((block) => {
  return ['mobile', 'tablet', 'desktop'].some((deviceName) => setting(block, deviceName as 'mobile' | 'tablet' | 'desktop', 'visible', true))
}))

const device = (block: CmsBlock, key: 'mobile' | 'tablet' | 'desktop') => block.responsive?.[key] || {}
const setting = (block: CmsBlock, key: 'mobile' | 'tablet' | 'desktop', path: string, fallback: unknown = null) => {
  return path.split('.').reduce<unknown>((value, segment) => {
    if (!value || typeof value !== 'object') return fallback
    return (value as Record<string, unknown>)[segment] ?? fallback
  }, device(block, key))
}

function visibilityClasses(block: CmsBlock): string {
  const mobile = setting(block, 'mobile', 'visible', true) ? 'block' : 'hidden'
  const tablet = setting(block, 'tablet', 'visible', true) ? 'md:block' : 'md:hidden'
  const desktop = setting(block, 'desktop', 'visible', true) ? 'xl:block' : 'xl:hidden'
  return [mobile, tablet, desktop].join(' ')
}

function containerClasses(block: CmsBlock): string {
  const width = setting(block, 'mobile', 'layout.width', 'full')
  return width === 'container' ? 'container-page' : 'w-full'
}

function gridClass(block: CmsBlock): string {
  return [
    columnClass('mobile', Number(setting(block, 'mobile', 'layout.columns', 1))),
    columnClass('tablet', Number(setting(block, 'tablet', 'layout.columns', 2))),
    columnClass('desktop', Number(setting(block, 'desktop', 'layout.columns', 4))),
  ].join(' ')
}

function columnClass(deviceName: 'mobile' | 'tablet' | 'desktop', columns: number): string {
  const mobile = ['grid-cols-1', 'grid-cols-2']
  const tablet = ['md:grid-cols-1', 'md:grid-cols-2', 'md:grid-cols-3', 'md:grid-cols-4']
  const desktop = ['xl:grid-cols-1', 'xl:grid-cols-2', 'xl:grid-cols-3', 'xl:grid-cols-4', 'xl:grid-cols-5', 'xl:grid-cols-6']
  const map = deviceName === 'desktop' ? desktop : (deviceName === 'tablet' ? tablet : mobile)
  return map[Math.max(1, Math.min(columns, map.length)) - 1]
}

function spacingClass(block: CmsBlock): string {
  const spacing = String(setting(block, 'mobile', 'layout.spacing', 'md'))
  return ({ none: 'gap-0', xs: 'gap-2', sm: 'gap-3', md: 'gap-4', lg: 'gap-6', xl: 'gap-8' } as Record<string, string>)[spacing] || 'gap-4'
}

function alignmentClass(block: CmsBlock): string {
  const alignment = String(setting(block, 'mobile', 'layout.alignment', 'left'))
  return ({ left: 'text-left', center: 'text-center', right: 'text-right' } as Record<string, string>)[alignment] || 'text-left'
}

function headingClass(block: CmsBlock): string {
  return [
    sizeClass(String(setting(block, 'mobile', 'typography.heading_size', '2xl'))),
    sizeClass(String(setting(block, 'tablet', 'typography.heading_size', '3xl')), 'md'),
    sizeClass(String(setting(block, 'desktop', 'typography.heading_size', '4xl')), 'xl'),
  ].join(' ')
}

function subtitleClass(block: CmsBlock): string {
  return [
    sizeClass(String(setting(block, 'mobile', 'typography.subtitle_size', 'md'))),
    sizeClass(String(setting(block, 'tablet', 'typography.subtitle_size', 'lg')), 'md'),
    sizeClass(String(setting(block, 'desktop', 'typography.subtitle_size', 'xl')), 'xl'),
  ].join(' ')
}

function textClass(block: CmsBlock): string {
  return [
    sizeClass(String(setting(block, 'mobile', 'typography.text_size', 'sm'))),
    sizeClass(String(setting(block, 'tablet', 'typography.text_size', 'md')), 'md'),
    sizeClass(String(setting(block, 'desktop', 'typography.text_size', 'md')), 'xl'),
  ].join(' ')
}

function sizeClass(size: string, prefix = ''): string {
  const map = { xs: 'text-xs', sm: 'text-sm', md: 'text-base', lg: 'text-lg', xl: 'text-xl', '2xl': 'text-2xl', '3xl': 'text-3xl', '4xl': 'text-4xl', custom: 'text-base' } as Record<string, string>
  const className = map[size] || 'text-base'
  return prefix ? `${prefix}:${className}` : className
}

function buttonGroupClass(block: CmsBlock): string {
  const stacked = setting(block, 'mobile', 'buttons.layout', 'stacked') === 'stacked'
  const alignment = String(setting(block, 'mobile', 'buttons.alignment', 'left'))
  return [
    stacked ? 'flex flex-col' : 'flex flex-wrap',
    alignment === 'center' ? 'justify-center' : alignment === 'right' ? 'justify-end' : 'justify-start',
  ].join(' ')
}

function buttonClass(block: CmsBlock): string {
  return setting(block, 'mobile', 'buttons.full_width', false) ? 'block w-full text-center' : 'inline-flex'
}

function orderClass(block: CmsBlock): string {
  return setting(block, 'mobile', 'ordering.media_first', false) ? '' : '[&>*:first-child]:order-2 [&>*:last-child]:order-1 md:[&>*:first-child]:order-1 md:[&>*:last-child]:order-2'
}

function hasResponsiveImage(block: CmsBlock): boolean {
  return Boolean(block.images?.mobile || block.images?.tablet || block.images?.desktop)
}

function productsForBlock(block: CmsBlock): ProductCard[] {
  return block.resolved?.products || props.relatedProducts
}

function categoriesForBlock(block: CmsBlock): Category[] {
  return block.resolved?.categories || props.relatedCategories
}

function blockStyle(block: CmsBlock): Record<string, string> {
  return {
    '--cms-height-mobile': String(setting(block, 'mobile', 'height', '320px')),
    '--cms-height-tablet': String(setting(block, 'tablet', 'height', '500px')),
    '--cms-height-desktop': String(setting(block, 'desktop', 'height', '700px')),
    '--cms-padding-top-mobile': String(setting(block, 'mobile', 'spacing.padding.top', '0') || '0'),
    '--cms-padding-right-mobile': String(setting(block, 'mobile', 'spacing.padding.right', '0') || '0'),
    '--cms-padding-bottom-mobile': String(setting(block, 'mobile', 'spacing.padding.bottom', '0') || '0'),
    '--cms-padding-left-mobile': String(setting(block, 'mobile', 'spacing.padding.left', '0') || '0'),
    '--cms-margin-top-mobile': String(setting(block, 'mobile', 'spacing.margin.top', '0') || '0'),
    '--cms-margin-bottom-mobile': String(setting(block, 'mobile', 'spacing.margin.bottom', '0') || '0'),
    '--cms-padding-top-tablet': String(setting(block, 'tablet', 'spacing.padding.top', '0') || '0'),
    '--cms-padding-right-tablet': String(setting(block, 'tablet', 'spacing.padding.right', '0') || '0'),
    '--cms-padding-bottom-tablet': String(setting(block, 'tablet', 'spacing.padding.bottom', '0') || '0'),
    '--cms-padding-left-tablet': String(setting(block, 'tablet', 'spacing.padding.left', '0') || '0'),
    '--cms-margin-top-tablet': String(setting(block, 'tablet', 'spacing.margin.top', '0') || '0'),
    '--cms-margin-bottom-tablet': String(setting(block, 'tablet', 'spacing.margin.bottom', '0') || '0'),
    '--cms-padding-top-desktop': String(setting(block, 'desktop', 'spacing.padding.top', '0') || '0'),
    '--cms-padding-right-desktop': String(setting(block, 'desktop', 'spacing.padding.right', '0') || '0'),
    '--cms-padding-bottom-desktop': String(setting(block, 'desktop', 'spacing.padding.bottom', '0') || '0'),
    '--cms-padding-left-desktop': String(setting(block, 'desktop', 'spacing.padding.left', '0') || '0'),
    '--cms-margin-top-desktop': String(setting(block, 'desktop', 'spacing.margin.top', '0') || '0'),
    '--cms-margin-bottom-desktop': String(setting(block, 'desktop', 'spacing.margin.bottom', '0') || '0'),
    maxWidth: String(setting(block, 'mobile', 'layout.max_width', 'none') || 'none'),
    marginLeft: 'auto',
    marginRight: 'auto',
  }
}
</script>

<style scoped>
.cms-block {
  --cms-height: var(--cms-height-mobile);
  padding: var(--cms-padding-top-mobile) var(--cms-padding-right-mobile) var(--cms-padding-bottom-mobile) var(--cms-padding-left-mobile);
  margin-top: var(--cms-margin-top-mobile);
  margin-bottom: var(--cms-margin-bottom-mobile);
}

@media (min-width: 768px) {
  .cms-block {
    --cms-height: var(--cms-height-tablet);
    padding: var(--cms-padding-top-tablet) var(--cms-padding-right-tablet) var(--cms-padding-bottom-tablet) var(--cms-padding-left-tablet);
    margin-top: var(--cms-margin-top-tablet);
    margin-bottom: var(--cms-margin-bottom-tablet);
  }
}

@media (min-width: 1200px) {
  .cms-block {
    --cms-height: var(--cms-height-desktop);
    padding: var(--cms-padding-top-desktop) var(--cms-padding-right-desktop) var(--cms-padding-bottom-desktop) var(--cms-padding-left-desktop);
    margin-top: var(--cms-margin-top-desktop);
    margin-bottom: var(--cms-margin-bottom-desktop);
  }
}
</style>
