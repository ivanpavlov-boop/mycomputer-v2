<template>
  <div>
    <Breadcrumbs :items="[{ label: 'AI асистент' }]" />
    <section class="container-page max-w-4xl">
      <h1 class="mb-6 text-3xl font-bold">AI продуктов асистент</h1>
      <AiChatWindow />
    </section>
  </div>
</template>

<script setup lang="ts">
useSeo().page('AI продуктов асистент', 'Интелигентни препоръки за избор на компютърна техника.', '/assistant')
</script>
