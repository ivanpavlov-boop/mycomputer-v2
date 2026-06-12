<template>
  <div class="space-y-6">
    <section v-for="group in groups" :key="group.group.slug" class="surface p-4">
      <h3 class="font-semibold">{{ group.group.name }}</h3>
      <dl class="mt-3 grid gap-2 text-sm">
        <div v-for="item in group.attributes" :key="item.attribute.slug" class="grid grid-cols-2 gap-3 border-b border-slate-100 py-2 last:border-0">
          <dt class="text-slate-500">{{ item.attribute.name }}</dt>
          <dd class="font-medium text-slate-900">{{ item.value.value }} <span v-if="item.attribute.unit">{{ item.attribute.unit }}</span></dd>
        </div>
      </dl>
    </section>
  </div>
</template>

<script setup lang="ts">
import type { ProductDetail } from '~/types/api'

defineProps<{ groups: ProductDetail['attributes'] }>()
</script>
