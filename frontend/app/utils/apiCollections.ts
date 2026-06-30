export interface ApiDataCollection<T> {
  data?: T[] | null
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
