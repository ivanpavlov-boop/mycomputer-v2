export const defaultCatalogSort = 'newest'

export const catalogSortOptions = [
  { label: 'Най-нови', value: 'newest' },
  { label: 'Цена възходящо', value: 'price_asc' },
] as const

export type CatalogSort = typeof catalogSortOptions[number]['value']

export const supportedCatalogSorts = new Set<string>(catalogSortOptions.map((option) => option.value))

export function normalizeCatalogSort(value: unknown): CatalogSort {
  const normalized = routeQueryString(value)

  return supportedCatalogSorts.has(normalized) ? normalized as CatalogSort : defaultCatalogSort
}

function routeQueryString(value: unknown): string {
  if (Array.isArray(value)) {
    return routeQueryString(value[0])
  }

  if (value === undefined || value === null) {
    return ''
  }

  if (typeof value === 'object') {
    if ('value' in value) {
      return routeQueryString(value.value)
    }

    return ''
  }

  return String(value).trim()
}
