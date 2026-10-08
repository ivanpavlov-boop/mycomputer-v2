// Fixed WebP/contain variants; the server also validates the size and public source.
export const getImage = (source: string, { modifiers = {} }: { modifiers?: Record<string, unknown> } = {}) => ({
  url: `/_ipx/cards/${Number(modifiers.width) || 320}/${encodeURIComponent(source)}`,
})

export const supportsAlias = true
