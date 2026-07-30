import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { isApprovedLegalManifest } from '../app/composables/useCommerceReleaseGate'
import legalManifest from '../app/data/legal/legal-content-manifest.json'

const frontendRoot = resolve(import.meta.dirname, '..')
const repositoryRoot = resolve(frontendRoot, '..')

function sha256(path: string) {
  return createHash('sha256').update(readFileSync(path)).digest('hex')
}

describe('legal content manifest', () => {
  it('ships the exact project-owner-approved Bulgarian legal contract', () => {
    expect(legalManifest).toMatchObject({
      locale: 'bg',
      status: 'approved',
      terms: {
        route: '/obshti-usloviya',
        version: 'bg-terms-v1.0-2026-07-30',
        effective_date: '2026-07-30',
      },
      privacy: {
        route: '/politika-za-poveritelnost',
        version: 'bg-privacy-v1.0-2026-07-30',
        effective_date: '2026-07-30',
      },
      approval: {
        approved_by_role: 'project_owner',
        approved_at: '2026-07-30',
        legal_counsel_review: 'not_claimed',
      },
    })
    expect(legalManifest.terms.source_sha256).toMatch(/^[a-f0-9]{64}$/)
    expect(legalManifest.privacy.source_sha256).toMatch(/^[a-f0-9]{64}$/)
    expect(isApprovedLegalManifest(legalManifest)).toBe(true)
  })

  it('binds approval to the exact legal source bytes and audit record', () => {
    const termsHash = sha256(resolve(frontendRoot, 'app/data/legal/terms.bg.ts'))
    const privacyHash = sha256(resolve(frontendRoot, 'app/data/legal/privacy.bg.ts'))
    const approval = JSON.parse(readFileSync(
      resolve(repositoryRoot, 'docs/legal/LEGAL_CONTENT_APPROVAL_2026-07-30.json'),
      'utf8',
    ))

    expect(legalManifest.terms.source_sha256).toBe(termsHash)
    expect(legalManifest.privacy.source_sha256).toBe(privacyHash)
    expect(approval).toMatchObject({
      phase: 'Legal Content Finalization and Explicit Approval',
      locale: legalManifest.locale,
      status: legalManifest.status,
      approved_by_role: legalManifest.approval.approved_by_role,
      approved_at: legalManifest.approval.approved_at,
      legal_counsel_review: legalManifest.approval.legal_counsel_review,
      terms_version: legalManifest.terms.version,
      terms_effective_date: legalManifest.terms.effective_date,
      terms_source_sha256: termsHash,
      privacy_version: legalManifest.privacy.version,
      privacy_effective_date: legalManifest.privacy.effective_date,
      privacy_source_sha256: privacyHash,
      base_commit: 'df7d59c387d3930560bc49e3fead0e5622881dbe',
    })
  })

  it('contains no customer data, secrets, or executable content', () => {
    const source = readFileSync(
      resolve(frontendRoot, 'app/data/legal/legal-content-manifest.json'),
      'utf8',
    )

    expect(source).not.toMatch(/customer|password|token|api[_-]?key|<script/i)
  })
})
