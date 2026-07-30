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
  const approval = candidate.approval

  return candidate.locale === 'bg'
    && candidate.status === 'approved'
    && isCompleteDocument(candidate.terms, '/obshti-usloviya')
    && isCompleteDocument(candidate.privacy, '/politika-za-poveritelnost')
    && isCompleteApproval(approval)
    && (candidate.terms as Record<string, unknown>).effective_date
      === (approval as Record<string, unknown>).approved_at
    && (candidate.privacy as Record<string, unknown>).effective_date
      === (approval as Record<string, unknown>).approved_at
}

function isCompleteDocument(value: unknown, route: string): boolean {
  if (!value || typeof value !== 'object') {
    return false
  }

  const document = value as Record<string, unknown>

  return document.route === route
    && typeof document.version === 'string'
    && document.version.trim() !== ''
    && isIsoDate(document.effective_date)
    && typeof document.source_sha256 === 'string'
    && /^[a-f0-9]{64}$/.test(document.source_sha256)
}

function isCompleteApproval(value: unknown): boolean {
  if (!value || typeof value !== 'object') {
    return false
  }

  const approval = value as Record<string, unknown>

  return approval.approved_by_role === 'project_owner'
    && isIsoDate(approval.approved_at)
    && approval.legal_counsel_review === 'not_claimed'
}

function isIsoDate(value: unknown): boolean {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    return false
  }

  const [year, month, day] = value.split('-').map(Number)
  const date = new Date(Date.UTC(year!, month! - 1, day!))

  return date.getUTCFullYear() === year
    && date.getUTCMonth() === month! - 1
    && date.getUTCDate() === day
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
