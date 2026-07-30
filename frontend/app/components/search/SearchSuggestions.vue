<template>
  <div class="surface p-4">
    <p class="font-semibold">Предложения</p>
    <div class="mt-3 flex flex-wrap gap-2">
      <NuxtLink v-for="suggestion in suggestions" :key="suggestion" :to="{ path: localePath('/catalog'), query: { search: suggestion } }" class="rounded-full bg-slate-100 px-3 py-1 text-sm hover:bg-brand-50 hover:text-brand-700">
        {{ suggestion }}
      </NuxtLink>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{ suggestions: string[] }>()

const localePath = useLocalePath()
</script>
