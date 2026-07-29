export type CommerceReleaseState = 'closed' | 'confirmation_only' | 'open' | 'invalid'

function normalizedFlag(value: unknown): boolean | null {
  if (value === true || value === 'true') {
    return true
  }

  if (value === false || value === 'false') {
    return false
  }

  return null
}

export function resolveCommerceReleaseState(
  commerceEnabled: unknown,
  confirmationEnabled: unknown,
): CommerceReleaseState {
  const commerce = normalizedFlag(commerceEnabled)
  const confirmation = normalizedFlag(confirmationEnabled)

  if (commerce === null || confirmation === null || (commerce && !confirmation)) {
    return 'invalid'
  }

  if (commerce) {
    return 'open'
  }

  return confirmation ? 'confirmation_only' : 'closed'
}

export function useCommerceReleaseGate() {
  const config = useRuntimeConfig()
  const state = computed(() => resolveCommerceReleaseState(
    config.public.commerceEnabled,
    config.public.commerceConfirmationEnabled,
  ))
  const canStartCheckout = computed(() => state.value === 'open')
  const canShowConfirmation = computed(() => ['confirmation_only', 'open'].includes(state.value))

  return {
    state,
    canStartCheckout,
    canShowConfirmation,
    isOpen: computed(() => state.value === 'open'),
    isConfirmationOnly: computed(() => state.value === 'confirmation_only'),
    isClosed: computed(() => state.value === 'closed'),
    isInvalid: computed(() => state.value === 'invalid'),
  }
}
