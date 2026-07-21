import { routeQueryValue } from './routeQuery'

export interface AttributeFilterSelection {
  values?: string[]
  min?: string
  max?: string
}

export type AttributeFilterSelections = Record<string, AttributeFilterSelection>

const optionPattern = /^attribute_filters\[([a-z0-9][a-z0-9_-]{0,99})\]\[\]$/
const rangePattern = /^attribute_filters\[([a-z0-9][a-z0-9_-]{0,99})\]\[(min|max)\]$/

export function parseAttributeFilterQuery(query: Record<string, unknown>): AttributeFilterSelections {
  const selections: AttributeFilterSelections = {}

  for (const [queryKey, rawValue] of Object.entries(query)) {
    const optionMatch = optionPattern.exec(queryKey)

    if (optionMatch) {
      const values = normalizeValues(rawValue)

      if (values.length) {
        selections[optionMatch[1]] = { values }
      }

      continue
    }

    const rangeMatch = rangePattern.exec(queryKey)

    if (!rangeMatch) {
      continue
    }

    const value = normalizeValues(rawValue)[0]

    if (!value) {
      continue
    }

    const key = rangeMatch[1]
    const operator = rangeMatch[2] as 'min' | 'max'
    selections[key] = { ...selections[key], [operator]: value }
  }

  return selections
}

export function attributeFilterApiQuery(query: Record<string, unknown>): Record<string, string | string[]> {
  const normalized: Record<string, string | string[]> = {}

  for (const [key, value] of Object.entries(query)) {
    if (!optionPattern.test(key) && !rangePattern.test(key)) {
      continue
    }

    const routeValue = routeQueryValue(value)

    if (routeValue !== undefined) {
      normalized[key] = routeValue
    }
  }

  return normalized
}

export function replaceAttributeFilter(
  query: Record<string, unknown>,
  key: string,
  selection: AttributeFilterSelection,
): Record<string, string | string[]> {
  const next = withoutAttributeFilter(query, key)
  const values = normalizeValues(selection.values)

  if (values.length) {
    next[`attribute_filters[${key}][]`] = values
  }

  if (selection.min?.trim()) {
    next[`attribute_filters[${key}][min]`] = selection.min.trim()
  }

  if (selection.max?.trim()) {
    next[`attribute_filters[${key}][max]`] = selection.max.trim()
  }

  delete next.page

  return normalizeRouteQuery(next)
}

export function clearAttributeFilters(query: Record<string, unknown>): Record<string, string | string[]> {
  const next = { ...query }

  for (const key of Object.keys(next)) {
    if (optionPattern.test(key) || rangePattern.test(key)) {
      delete next[key]
    }
  }

  delete next.page

  return normalizeRouteQuery(next)
}

export function hasAttributeFilters(query: Record<string, unknown>): boolean {
  return Object.keys(query).some((key) => optionPattern.test(key) || rangePattern.test(key))
}

function withoutAttributeFilter(query: Record<string, unknown>, attributeKey: string): Record<string, unknown> {
  const next = { ...query }

  for (const key of Object.keys(next)) {
    const optionMatch = optionPattern.exec(key)
    const rangeMatch = rangePattern.exec(key)

    if (optionMatch?.[1] === attributeKey || rangeMatch?.[1] === attributeKey) {
      delete next[key]
    }
  }

  return next
}

function normalizeRouteQuery(query: Record<string, unknown>): Record<string, string | string[]> {
  const normalized: Record<string, string | string[]> = {}

  for (const [key, value] of Object.entries(query)) {
    const routeValue = routeQueryValue(value)

    if (routeValue !== undefined) {
      normalized[key] = routeValue
    }
  }

  return normalized
}

function normalizeValues(value: unknown): string[] {
  const values = Array.isArray(value) ? value : [value]

  return values
    .filter((item): item is string | number => typeof item === 'string' || typeof item === 'number')
    .map((item) => String(item).trim())
    .filter(Boolean)
    .filter((item, index, all) => all.indexOf(item) === index)
}
