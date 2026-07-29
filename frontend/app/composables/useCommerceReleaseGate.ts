import legalManifest from '#legal-content-manifest'

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
  legalContentApproved: unknown = false,
  manifest: unknown = legalManifest,
): CommerceReleaseState {
  const commerce = normalizedFlag(commerceEnabled)
  const confirmation = normalizedFlag(confirmationEnabled)

  if (commerce === null || confirmation === null || (commerce && !confirmation)) {
    return 'invalid'
  }

  if (commerce) {
    return normalizedFlag(legalContentApproved) === true && isApprovedLegalManifest(manifest)
      ? 'open'
      : 'invalid'
  }

  return confirmation ? 'confirmation_only' : 'closed'
}

export function isApprovedLegalManifest(value: unknown): boolean {
  if (!value || typeof value !== 'object') {
    return false
  }

  const candidate = value as Record<string, unknown>

  return candidate.locale === 'bg'
    && candidate.status === 'approved'
    && isCompleteDocument(candidate.terms, '/obshti-usloviya')
    && isCompleteDocument(candidate.privacy, '/politika-za-poveritelnost')
}

function isCompleteDocument(value: unknown, route: string): boolean {
  if (!value || typeof value !== 'object') {
    return false
  }

  const document = value as Record<string, unknown>

  return document.route === route
    && typeof document.version === 'string'
    && document.version.trim() !== ''
    && typeof document.effective_date === 'string'
    && document.effective_date.trim() !== ''
}

export function useCommerceReleaseGate() {
  const config = useRuntimeConfig()
  const state = computed(() => resolveCommerceReleaseState(
    config.public.commerceEnabled,
    config.public.commerceConfirmationEnabled,
    config.public.legalContentApproved,
    legalManifest,
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
