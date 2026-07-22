import { queryString, routeQueryValue } from './routeQuery'

export interface PriceFilterSelection {
  min?: string
  max?: string
}

export function parsePriceFilterQuery(query: Record<string, unknown>): PriceFilterSelection {
  return {
    min: normalizedPrice(query.price_min),
    max: normalizedPrice(query.price_max),
  }
}

export function hasPriceFilters(query: Record<string, unknown>): boolean {
  const selection = parsePriceFilterQuery(query)

  return Boolean(selection.min || selection.max)
}

export function replacePriceFilters(
  query: Record<string, unknown>,
  selection: PriceFilterSelection,
): Record<string, string | string[]> {
  const next: Record<string, unknown> = {
    ...query,
    price_min: selection.min,
    price_max: selection.max,
    page: undefined,
  }
  const normalized: Record<string, string | string[]> = {}

  for (const [key, value] of Object.entries(next)) {
    const routeValue = routeQueryValue(value)

    if (routeValue !== undefined) {
      normalized[key] = routeValue
    }
  }

  return normalized
}

export function clearPriceFilters(query: Record<string, unknown>): Record<string, string | string[]> {
  return replacePriceFilters(query, {})
}

function normalizedPrice(value: unknown): string | undefined {
  const normalized = queryString(value)

  if (!normalized) {
    return undefined
  }

  const number = Number(normalized)

  return Number.isFinite(number) && number >= 0 ? normalized : undefined
}
