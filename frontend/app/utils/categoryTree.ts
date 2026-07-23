import type { Category } from '~/types/api'

export interface CategoryTreeSummary {
  rootCategoryCount: number
  totalCategoryCount: number
  maximumVisibleDepth: number
}

function validCategoryId(category: Category): number | null {
  return Number.isInteger(category.id) && category.id > 0 ? category.id : null
}

export function normalizeCategoryTree(categories: Category[]): Category[] {
  const emittedIds = new Set<number>()

  function normalize(nodes: Category[], ancestorIds: ReadonlySet<number>): Category[] {
    const normalized: Category[] = []

    for (const category of nodes) {
      const id = validCategoryId(category)

      if (id === null || ancestorIds.has(id) || emittedIds.has(id)) {
        continue
      }

      emittedIds.add(id)

      const nextAncestorIds = new Set(ancestorIds)
      nextAncestorIds.add(id)

      normalized.push({
        ...category,
        children: normalize(category.children || [], nextAncestorIds),
      })
    }

    return normalized
  }

  return normalize(categories, new Set())
}

export function categoryTreeSummary(categories: Category[]): CategoryTreeSummary {
  const seenIds = new Set<number>()
  let rootCategoryCount = 0
  let totalCategoryCount = 0
  let maximumVisibleDepth = 0

  const stack = categories
    .map(category => ({ category, depth: 1 }))
    .reverse()

  while (stack.length) {
    const current = stack.pop()

    if (!current) {
      continue
    }

    const id = validCategoryId(current.category)

    if (id === null || seenIds.has(id)) {
      continue
    }

    seenIds.add(id)
    totalCategoryCount++
    maximumVisibleDepth = Math.max(maximumVisibleDepth, current.depth)

    if (current.depth === 1) {
      rootCategoryCount++
    }

    const children = current.category.children || []

    for (let index = children.length - 1; index >= 0; index--) {
      stack.push({
        category: children[index]!,
        depth: current.depth + 1,
      })
    }
  }

  return {
    rootCategoryCount,
    totalCategoryCount,
    maximumVisibleDepth,
  }
}
