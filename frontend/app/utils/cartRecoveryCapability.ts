const recoveryCapabilityPattern = /^[A-Za-z0-9_-]{43}$/

interface RecoveryLocation {
  hash: string
  pathname: string
}

interface RecoveryHistory {
  state: unknown
  replaceState: (data: unknown, unused: string, url?: string | URL | null) => void
}

export function readAndClearRecoveryCapability(
  location: RecoveryLocation,
  history: RecoveryHistory,
): string | null {
  const capability = location.hash.startsWith('#')
    ? location.hash.slice(1)
    : ''

  history.replaceState(history.state, '', location.pathname)

  return recoveryCapabilityPattern.test(capability) ? capability : null
}

export function isRecoveryCapability(value: unknown): value is string {
  return typeof value === 'string' && recoveryCapabilityPattern.test(value)
}
