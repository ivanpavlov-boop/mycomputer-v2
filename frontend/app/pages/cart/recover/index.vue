<template>
  <div class="container-page py-10">
    <p v-if="pending" class="text-sm text-slate-600" role="status">
      &#1042;&#1098;&#1079;&#1089;&#1090;&#1072;&#1085;&#1086;&#1074;&#1103;&#1074;&#1072;&#1085;&#1077; &#1085;&#1072; &#1082;&#1086;&#1083;&#1080;&#1095;&#1082;&#1072;&#1090;&#1072;...
    </p>
    <div v-else class="rounded-lg border border-red-200 bg-red-50 p-5 text-red-800" role="alert">
      <p class="text-sm">{{ failureMessage }}</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { readAndClearRecoveryCapability } from '~/utils/cartRecoveryCapability'

definePageMeta({
  middleware: 'cart-recovery',
})

const config = useRuntimeConfig()
const router = useRouter()
const cart = useCartStore()
const pending = ref(true)
const failureMessage = '\u041b\u0438\u043d\u043a\u044a\u0442 \u0437\u0430 \u0432\u044a\u0437\u0441\u0442\u0430\u043d\u043e\u0432\u044f\u0432\u0430\u043d\u0435 \u043d\u0435 \u0435 \u043d\u0430\u043b\u0438\u0447\u0435\u043d \u0438\u043b\u0438 \u0435 \u0438\u0437\u0442\u0435\u043a\u044a\u043b.'
const canonical = new URL('/cart/recover', config.public.siteUrl).toString()

if (import.meta.server) {
  useResponseHeader('Cache-Control').value = 'private, no-store, max-age=0'
  useResponseHeader('Pragma').value = 'no-cache'
  useResponseHeader('Referrer-Policy').value = 'no-referrer'
}

onMounted(async () => {
  let capability = readAndClearRecoveryCapability(window.location, window.history)
  await router.replace('/cart/recover')

  if (capability === null) {
    pending.value = false

    return
  }

  try {
    await cart.recover(capability)
    capability = null
    await router.replace('/cart')
  }
  catch {
    capability = null
    pending.value = false
  }
})

useSeoMeta({
  title: '\u0412\u044a\u0437\u0441\u0442\u0430\u043d\u043e\u0432\u044f\u0432\u0430\u043d\u0435 \u043d\u0430 \u043a\u043e\u043b\u0438\u0447\u043a\u0430',
  description: '\u0412\u044a\u0437\u0441\u0442\u0430\u043d\u043e\u0432\u044f\u0432\u0430\u043d\u0435 \u043d\u0430 \u0437\u0430\u043f\u0430\u0437\u0435\u043d\u0430 \u043a\u043e\u043b\u0438\u0447\u043a\u0430.',
})
useHead({
  link: [{ rel: 'canonical', href: canonical }],
  meta: [
    { name: 'robots', content: 'noindex, nofollow, noarchive' },
    { name: 'referrer', content: 'no-referrer' },
  ],
})
</script>
