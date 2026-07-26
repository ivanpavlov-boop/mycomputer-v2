<template>
  <div>
    <form class="flex flex-wrap gap-2" :aria-busy="pending" @submit.prevent="apply">
      <UiBaseInput
        v-model="code"
        :disabled="pending"
        aria-label="Код за купон"
        placeholder="Промо код"
        @update:model-value="clearFeedback"
      />
      <UiBaseButton type="submit" :disabled="pending || !code.trim()">
        {{ applying ? 'Прилагане…' : 'Приложи' }}
      </UiBaseButton>
      <button
        v-if="cart.couponCode"
        class="text-sm font-semibold text-red-600 disabled:opacity-50"
        type="button"
        :disabled="pending"
        @click="remove"
      >
        {{ removing ? 'Премахване…' : 'Премахни' }}
      </button>
    </form>
    <p
      v-if="feedback"
      class="mt-2 text-xs"
      :class="feedbackType === 'error' ? 'text-red-700' : 'text-emerald-700'"
      :role="feedbackType === 'error' ? 'alert' : 'status'"
    >
      {{ feedback }}
    </p>
  </div>
</template>

<script setup lang="ts">
const cart = useCartStore()
const code = ref(cart.couponCode || '')
const feedback = ref('')
const feedbackType = ref<'success' | 'error'>('success')
const applyKey = 'coupon:apply'
const removeKey = 'coupon:remove'
const applying = computed(() => cart.isOperationPending(applyKey))
const removing = computed(() => cart.isOperationPending(removeKey))
const pending = computed(() => applying.value || removing.value)

watch(() => cart.couponCode, value => {
  code.value = value || ''
})

function clearFeedback() {
  feedback.value = ''
}

async function apply() {
  if (!code.value.trim()) return

  clearFeedback()
  const confirmed = await cart.applyCoupon(code.value.trim()).catch(() => null)

  if (confirmed) {
    feedbackType.value = 'success'
    feedback.value = 'Купонът е приложен.'
  } else if (cart.errorFor(applyKey)) {
    feedbackType.value = 'error'
    feedback.value = cart.errorFor(applyKey)?.message || ''
  }
}

async function remove() {
  clearFeedback()
  const confirmed = await cart.removeCoupon().catch(() => null)

  if (confirmed) {
    code.value = ''
    feedbackType.value = 'success'
    feedback.value = 'Купонът е премахнат.'
  } else if (cart.errorFor(removeKey)) {
    feedbackType.value = 'error'
    feedback.value = cart.errorFor(removeKey)?.message || ''
  }
}
</script>
