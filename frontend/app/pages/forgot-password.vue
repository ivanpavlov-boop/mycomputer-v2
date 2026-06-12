<template>
  <div class="container-page py-10">
    <ForgotPasswordForm />
  </div>
</template>

<script setup lang="ts">
useSeo().page('Забравена парола', 'Възстановяване на парола.', '/forgot-password')
</script>
