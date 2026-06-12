<template>
  <div>
    <Breadcrumbs :items="[{ label: title }]" />
    <section class="container-page">
      <div class="surface max-w-3xl p-8">
        <h1 class="text-3xl font-bold">{{ title }}</h1>
        <p class="mt-4 text-slate-700"><slot /></p>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
const props = defineProps<{ title: string }>()
useSeo().page(props.title, `${props.title} - mycomputer.bg`, useRoute().path)
</script>
