import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const projectRoot = resolve(import.meta.dirname, '../..')
const source = (path: string) => readFileSync(resolve(projectRoot, path), 'utf8')

describe('payment API client lockdown', () => {
  it('keeps discovery but removes public payment initiation', () => {
    const payments = source('frontend/app/composables/usePayments.ts')
    const routes = source('routes/api.php')

    expect(payments).toContain("api.get<ApiCollection<PaymentMethod>>('/payments/methods')")
    expect(payments).not.toContain('/payments/initiate')
    expect(payments).not.toMatch(/const initiate|return \{ methods, initiate \}/)
    expect(routes).not.toContain("Route::post('payments/initiate'")
  })
})
