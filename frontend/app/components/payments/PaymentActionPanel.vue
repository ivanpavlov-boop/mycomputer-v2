<template>
  <section
    ref="statusRegion"
    class="mt-5 rounded-md border border-slate-200 bg-slate-50 p-4"
    tabindex="-1"
    aria-live="polite"
  >
    <p class="font-semibold text-slate-900">{{ current.status_label }}</p>
    <p class="mt-1 text-sm text-slate-700">{{ current.message }}</p>
    <p v-if="current.instructions" class="mt-3 whitespace-pre-line text-sm text-slate-700">
      {{ current.instructions }}
    </p>

    <p v-if="errorMessage" class="mt-3 text-sm font-semibold text-red-700" role="alert">
      {{ errorMessage }}
    </p>

    <a
      v-if="canContinue"
      :href="current.redirect_url!"
      class="mt-4 inline-flex min-h-11 items-center rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2"
      target="_blank"
      rel="noopener noreferrer"
      referrerpolicy="no-referrer"
    >
      {{ current.action.label }}
    </a>

    <button
      v-else-if="canRetry"
      type="button"
      class="mt-4 min-h-11 rounded-md bg-red-700 px-4 py-2 text-sm font-semibold text-white focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
      :disabled="pending"
      @click="retryPayment"
    >
      {{ pending ? 'Обработва се…' : current.action.label }}
    </button>
  </section>
</template>

<script setup lang="ts">
import type { PaymentActionPresentation } from '~/types/api'

const props = withDefaults(defineProps<{
  presentation: PaymentActionPresentation
  mode?: 'guest' | 'account'
  orderId?: number | null
}>(), {
  mode: 'guest',
  orderId: null,
})

const attempts = usePaymentAttempts()
const current = ref<PaymentActionPresentation>(props.presentation)
const pending = ref(false)
const errorMessage = ref<string | null>(null)
const statusRegion = ref<HTMLElement | null>(null)

const canContinue = computed(() => (
  current.value.action.available
  && current.value.action.type === 'continue_payment'
  && typeof current.value.redirect_url === 'string'
  && current.value.redirect_url.startsWith('https://')
))
const canRetry = computed(() => (
  current.value.action.available
  && current.value.action.type === 'retry_payment'
))

watch(() => props.presentation, value => {
  current.value = value
  errorMessage.value = null
})

async function retryPayment() {
  if (pending.value || !canRetry.value) {
    return
  }

  pending.value = true
  errorMessage.value = null

  try {
    const response = props.mode === 'account' && props.orderId !== null
      ? await attempts.retryAccountOrder(props.orderId)
      : await attempts.retryGuestOrder()

    current.value = response.data.payment.presentation
  } catch (error) {
    const code = typeof error === 'object' && error !== null && 'code' in error
      ? String(error.code)
      : 'request_failed'

    if (code === 'payment_already_paid') {
      current.value = paidPresentation()
    } else {
      errorMessage.value = paymentErrorMessage(code)
    }
  } finally {
    pending.value = false
    await nextTick()
    statusRegion.value?.focus()
  }
}

function paidPresentation(): PaymentActionPresentation {
  return {
    state: 'paid',
    status_label: 'Платено',
    message: 'Плащането е потвърдено.',
    action: {
      type: 'none',
      label: null,
      available: false,
    },
    redirect_url: null,
    instructions: null,
    currency: current.value.currency,
  }
}

function paymentErrorMessage(code: string): string {
  return {
    payment_retry_unavailable: 'Заявката за повторно плащане вече не е налична. Свържете се с нас за съдействие.',
    payment_attempt_in_progress: 'Платежният опит се обработва. Изчакайте и опитайте отново.',
    payment_result_indeterminate: 'Резултатът от плащането още не е потвърден. Не създавайте нова поръчка.',
    payment_provider_failed: 'Плащането не беше прието. Опитайте отново само ако желаете да направите нов изричен опит.',
  }[code] ?? 'Възникна проблем при плащането. Свържете се с нас за съдействие.'
}
</script>
