import { defineStore } from 'pinia'

export const useUiStore = defineStore('ui', () => {
  const mobileMenuOpen = ref(false)
  const cartOpen = ref(false)
  const filtersOpen = ref(false)

  return { mobileMenuOpen, cartOpen, filtersOpen }
})
