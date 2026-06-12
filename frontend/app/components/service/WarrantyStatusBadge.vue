<template>
  <span
    class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
    :class="warranty?.in_warranty ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'"
  >
    {{ warranty?.in_warranty ? `В гаранция до ${warranty.valid_until}` : 'Извън гаранция' }}
  </span>
</template>

<script setup lang="ts">
defineProps<{ warranty?: { in_warranty: boolean; valid_until?: string | null } | null }>()
</script>
