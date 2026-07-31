<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'is_company' => ['required', 'boolean'],
            'company_name' => ['exclude_unless:is_company,true', 'required_if:is_company,true', 'nullable', 'string', 'max:255'],
            'vat_number' => ['exclude_unless:is_company,true', 'nullable', 'string', 'max:50'],
            'billing_address' => ['exclude_unless:is_company,true', 'required_if:is_company,true', 'nullable', 'string', 'max:1000'],
            'shipping_address' => ['required', 'string', 'max:1000'],
            'payment_method' => ['required', 'in:cash_on_delivery,bank_transfer,card,leasing'],
            'shipping_method' => ['required', 'string', 'max:255'],
            'shipping_provider' => ['nullable', 'string', 'max:255'],
            'delivery_type' => ['nullable', 'in:office,address,manual'],
            'office_id' => ['required_if:delivery_type,office', 'nullable', 'integer', 'exists:shipping_offices,id'],
            'office_name' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:50'],
            'reward_code' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'terms' => ['accepted'],
            'terms_version' => ['prohibited'],
            'privacy_version' => ['prohibited'],
            'legal_accepted_at' => ['prohibited'],
            'legal_acceptance_locale' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'is_company.required' => 'Изберете типа на фактуриране.',
            'is_company.boolean' => 'Типът на фактуриране е невалиден.',
            'company_name.required_if' => 'Въведете име на фирмата.',
            'billing_address.required_if' => 'Въведете адрес за фактуриране.',
        ];
    }

    public function checkoutData(): array
    {
        $data = $this->validated();
        $data['is_company'] = (bool) $data['is_company'];

        if (! $data['is_company']) {
            $data['company_name'] = null;
            $data['vat_number'] = null;
            $data['billing_address'] = $data['shipping_address'];
        }

        return $data;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('is_company')) {
            $this->merge(['is_company' => false]);
        }
    }
}
