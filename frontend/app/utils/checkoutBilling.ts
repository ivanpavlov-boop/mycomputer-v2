export interface CheckoutBillingForm {
  company_name: string
  vat_number: string
  billing_address: string
  shipping_address: string
}

export function clearCompanyBilling(form: CheckoutBillingForm): void {
  form.company_name = ''
  form.vat_number = ''
  form.billing_address = ''
}

export function checkoutBillingPayload(
  form: CheckoutBillingForm,
  isCompany: boolean,
): Record<string, unknown> {
  return {
    is_company: isCompany,
    company_name: isCompany ? form.company_name : null,
    vat_number: isCompany ? (form.vat_number || null) : null,
    billing_address: isCompany ? form.billing_address : form.shipping_address,
  }
}
