<template>
  <div v-if="filters.length">
    <button
      ref="trigger"
      type="button"
      class="mb-4 inline-flex w-full items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-brand-500 lg:hidden"
      aria-haspopup="dialog"
      :aria-expanded="mobileOpen"
      @click="openMobile"
    >
      Филтри
      <span v-if="activeCount" class="rounded-full bg-brand-700 px-2 py-0.5 text-xs text-white">{{ activeCount }}</span>
    </button>

    <aside class="hidden border-r border-slate-200 pr-5 lg:block" aria-label="Филтри по характеристики">
      <div class="mb-4 flex items-center justify-between gap-3">
        <h2 class="text-lg font-bold text-slate-950">Филтри</h2>
        <button
          v-if="activeCount"
          type="button"
          class="text-xs font-semibold text-brand-700 hover:text-brand-900 focus:outline-none focus:ring-2 focus:ring-brand-500"
          @click="$emit('clear-all')"
        >
          Изчисти всички
        </button>
      </div>
      <CatalogAttributeFilterGroups :filters="filters" :selection="selection" @change="forwardChange" />
    </aside>

    <Teleport to="body">
      <div v-if="mobileOpen" class="fixed inset-0 z-50 lg:hidden" @keydown.esc="closeMobile">
        <button class="absolute inset-0 bg-slate-950/50" type="button" aria-label="Затвори филтрите" @click="closeMobile" />
        <section
          role="dialog"
          aria-modal="true"
          aria-labelledby="mobile-filter-title"
          class="absolute inset-y-0 right-0 flex w-full max-w-sm flex-col bg-white shadow-xl"
        >
          <header class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
            <h2 id="mobile-filter-title" class="text-lg font-bold text-slate-950">Филтри</h2>
            <button
              ref="closeButton"
              type="button"
              class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-brand-500"
              @click="closeMobile"
            >
              Затвори
            </button>
          </header>
          <div class="min-h-0 flex-1 overflow-y-auto px-4 py-4">
            <CatalogAttributeFilterGroups :filters="filters" :selection="selection" @change="forwardChange" />
          </div>
          <footer class="grid grid-cols-2 gap-3 border-t border-slate-200 p-4">
            <UiBaseButton variant="secondary" :disabled="!activeCount" @click="$emit('clear-all')">
              Изчисти
            </UiBaseButton>
            <UiBaseButton @click="closeMobile">Покажи продуктите</UiBaseButton>
          </footer>
        </section>
      </div>
    </Teleport>
  </div>
</template>

<script setup lang="ts">
import type { PublicProductAttributeFilter } from '~/types/api'
import type { AttributeFilterSelection, AttributeFilterSelections } from '~/utils/attributeFilters'

const props = defineProps<{
  filters: PublicProductAttributeFilter[]
  selection: AttributeFilterSelections
  activeCount: number
}>()

const emit = defineEmits<{
  change: [key: string, selection: AttributeFilterSelection]
  'clear-all': []
}>()

const mobileOpen = ref(false)
const trigger = ref<HTMLButtonElement | null>(null)
const closeButton = ref<HTMLButtonElement | null>(null)

async function openMobile() {
  mobileOpen.value = true
  await nextTick()
  closeButton.value?.focus()
}

async function closeMobile() {
  mobileOpen.value = false
  await nextTick()
  trigger.value?.focus()
}

function forwardChange(key: string, selection: AttributeFilterSelection) {
  emit('change', key, selection)
}

watch(() => props.filters.length, (length) => {
  if (!length) {
    mobileOpen.value = false
  }
})
</script>
