export function queryString(value: unknown): string {
  if (Array.isArray(value)) {
    return queryString(value[0])
  }

  if (value === undefined || value === null) {
    return ''
  }

  if (typeof value === 'object') {
    return ''
  }

  return String(value).trim()
}

export function positiveInteger(value: unknown, fallback: number): number {
  const parsed = Number.parseInt(queryString(value), 10)

  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback
}

export function routeQueryValue(value: unknown): string | string[] | undefined {
  if (Array.isArray(value)) {
    const values = value.map((item) => queryString(item)).filter(Boolean)

    return values.length ? values : undefined
  }

  const normalized = queryString(value)

  return normalized || undefined
}
