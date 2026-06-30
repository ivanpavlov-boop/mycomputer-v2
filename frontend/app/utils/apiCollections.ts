export interface ApiDataCollection<T> {
  data?: T[] | null
  meta?: Record<string, unknown> | null
}

export interface ApiDataResource<T> {
  data?: T | null
}

export function collectionData<T>(response: ApiDataCollection<T> | T[] | null | undefined): T[] {
  if (Array.isArray(response)) {
    return response
  }

  if (Array.isArray(response?.data)) {
    return response.data
  }

  return []
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
