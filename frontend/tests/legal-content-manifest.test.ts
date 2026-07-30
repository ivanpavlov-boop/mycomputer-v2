import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { isApprovedLegalManifest } from '../app/composables/useCommerceReleaseGate'
import legalManifest from '../app/data/legal/legal-content-manifest.json'

const frontendRoot = resolve(import.meta.dirname, '..')

describe('legal content manifest', () => {
  it('ships a complete draft contract without approving commerce', () => {
    expect(legalManifest).toEqual({
      locale: 'bg',
      status: 'draft',
      terms: {
        route: '/obshti-usloviya',
        version: 'draft-1',
        effective_date: null,
      },
      privacy: {
        route: '/politika-za-poveritelnost',
        version: 'draft-1',
        effective_date: null,
      },
    })
    expect(isApprovedLegalManifest(legalManifest)).toBe(false)
  })

  it('contains no customer data, secrets, or executable content', () => {
    const source = readFileSync(
      resolve(frontendRoot, 'app/data/legal/legal-content-manifest.json'),
      'utf8',
    )

    expect(source).not.toMatch(/customer|password|token|api[_-]?key|<script/i)
  })
})
