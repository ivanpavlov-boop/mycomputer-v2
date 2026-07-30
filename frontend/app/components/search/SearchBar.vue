<template>
  <form class="relative" @submit.prevent="submit">
    <UiBaseInput v-model="q" placeholder="Търсене на продукти, марки, категории..." />
    <button class="absolute right-2 top-1/2 -translate-y-1/2 rounded-md bg-brand-600 px-3 py-1 text-sm font-semibold text-white">
      Търси
    </button>
  </form>
</template>

<script setup lang="ts">
const router = useRouter()
const route = useRoute()
const localePath = useLocalePath()
const q = ref(String(route.query.search || route.query.q || ''))

function submit() {
  const search = q.value.trim()

  router.push({
    path: localePath('/catalog'),
    query: search ? { search } : {},
  })
}
</script>
