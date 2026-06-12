import type { ApiCollection, ProductReview, ProductReviewSummary } from '~/types/api'

interface ReviewListResponse extends ApiCollection<ProductReview> {
  summary: ProductReviewSummary
}

export function useReviews() {
  const config = useRuntimeConfig()
  const auth = useAuthStore()

  function reviewHeaders() {
    const headers: Record<string, string> = { ...auth.authHeaders() }
    if (import.meta.client) {
      let session = localStorage.getItem('review_session_id')
      if (!session) {
        session = crypto.randomUUID()
        localStorage.setItem('review_session_id', session)
      }
      headers['X-Review-Session'] = session
    }
    return headers
  }

  async function list(productSlug: string, query?: Record<string, unknown>) {
    return await $fetch<ReviewListResponse>(`/products/${productSlug}/reviews`, {
      baseURL: config.public.apiBaseUrl,
      query,
      headers: reviewHeaders(),
    })
  }

  async function submit(productSlug: string, payload: Record<string, unknown>) {
    return await $fetch<{ data: ProductReview }>(`/products/${productSlug}/reviews`, {
      baseURL: config.public.apiBaseUrl,
      method: 'POST',
      body: payload,
      headers: reviewHeaders(),
    })
  }

  async function vote(reviewId: number, voteType: 'helpful' | 'not_helpful') {
    return await $fetch(`/reviews/${reviewId}/vote`, {
      baseURL: config.public.apiBaseUrl,
      method: 'POST',
      body: { vote_type: voteType },
      headers: reviewHeaders(),
    })
  }

  async function report(reviewId: number, payload: { reason: string, message?: string }) {
    return await $fetch(`/reviews/${reviewId}/report`, {
      baseURL: config.public.apiBaseUrl,
      method: 'POST',
      body: payload,
      headers: reviewHeaders(),
    })
  }

  async function account() {
    return await $fetch<ApiCollection<ProductReview>>('/account/reviews', {
      baseURL: config.public.apiBaseUrl,
      headers: auth.authHeaders(),
    })
  }

  return { list, submit, vote, report, account }
}
