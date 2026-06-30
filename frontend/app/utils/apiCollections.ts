export interface ApiDataCollection<T> {
  data?: T[] | null
  links?: Record<string, unknown> | unknown[] | null
  meta?: ApiCollectionMeta | null
}

export interface ApiDataResource<T> {
  data?: T | null
}

export interface ApiCollectionMeta {
  current_page?: number
  last_page?: number
  per_page?: number
  total?: number
  [key: string]: unknown
}

export interface NormalizedApiCollection<T> {
  data: T[]
  links: Record<string, unknown>
  meta?: ApiCollectionMeta
}

export function collectionData<T>(response: ApiDataCollection<T> | T[] | null | undefined): T[] {
  return resourceCollection(response).data
}

export function resourceCollection<T>(response: ApiDataCollection<T> | T[] | null | undefined): NormalizedApiCollection<T> {
  if (Array.isArray(response)) {
    return {
      data: response,
      links: {},
    }
  }

  if (!response || typeof response !== 'object') {
    return emptyCollection()
  }

  return {
    data: Array.isArray(response.data) ? response.data : [],
    links: plainObject(response.links) ? response.links : {},
    meta: plainObject(response.meta) ? normalizeMeta(response.meta) : undefined,
  }
}

export function paginatedResource<T>(response: ApiDataCollection<T> | T[] | null | undefined): NormalizedApiCollection<T> {
  return resourceCollection(response)
}

export function resourceData<T>(response: ApiDataResource<T> | T | null | undefined): T | null {
  if (!response || Array.isArray(response)) {
    return null
  }

  if (typeof response === 'object' && 'data' in response) {
    return (response as ApiDataResource<T>).data ?? null
  }

  return response as T
}

function emptyCollection<T>(): NormalizedApiCollection<T> {
  return {
    data: [],
    links: {},
  }
}

function plainObject(value: unknown): value is Record<string, unknown> {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value)
}

function normalizeMeta(value: Record<string, unknown>): ApiCollectionMeta {
  return {
    ...value,
    current_page: optionalNumber(value.current_page),
    last_page: optionalNumber(value.last_page),
    per_page: optionalNumber(value.per_page),
    total: optionalNumber(value.total),
  }
}

function optionalNumber(value: unknown): number | undefined {
  const number = Number(value)

  return Number.isFinite(number) ? number : undefined
}
