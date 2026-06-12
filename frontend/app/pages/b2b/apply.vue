<template>
  <div class="container-page py-10">
    <section class="mx-auto max-w-2xl surface p-6">
      <h1 class="text-2xl font-bold">Кандидатстване за B2B профил</h1>
      <p class="mt-2 text-sm text-slate-600">Попълнете фирмените данни. Заявката ще бъде прегледана от администратор.</p>
      <div class="mt-6">
        <B2BApplyForm />
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
useSeo().page('B2B кандидатстване', 'Заявка за фирмен B2B профил.', '/b2b/apply')
</script>
