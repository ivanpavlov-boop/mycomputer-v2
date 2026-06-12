import type { AiConversation, AiRecommendation } from '~/types/api'

export function useAiAssistant() {
  const config = useRuntimeConfig()
  const auth = useAuthStore()

  function headers() {
    const next: Record<string, string> = { ...auth.authHeaders() }
    if (import.meta.client) {
      let session = localStorage.getItem('ai_session_id')
      if (!session) {
        session = crypto.randomUUID()
        localStorage.setItem('ai_session_id', session)
      }
      next['X-AI-Session'] = session
    }
    return next
  }

  const chat = (message: string, conversationId?: number) => $fetch<{ data: AiConversation }>('/ai/chat', {
    baseURL: config.public.apiBaseUrl,
    method: 'POST',
    body: { message, conversation_id: conversationId },
    headers: headers(),
  })

  const search = (query: string) => $fetch<{ data: AiRecommendation }>('/ai/search', {
    baseURL: config.public.apiBaseUrl,
    method: 'POST',
    body: { query },
    headers: headers(),
  })

  const conversations = () => $fetch<{ data: AiConversation[] }>('/ai/conversations', {
    baseURL: config.public.apiBaseUrl,
    headers: headers(),
  })

  const conversation = (id: number) => $fetch<{ data: AiConversation }>(`/ai/conversations/${id}`, {
    baseURL: config.public.apiBaseUrl,
    headers: headers(),
  })

  const removeConversation = (id: number) => $fetch(`/ai/conversations/${id}`, {
    baseURL: config.public.apiBaseUrl,
    method: 'DELETE',
    headers: headers(),
  })

  return { chat, search, conversations, conversation, removeConversation }
}
