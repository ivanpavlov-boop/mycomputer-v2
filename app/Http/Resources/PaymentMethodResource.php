<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $resource = [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type,
            'description' => $this->description,
            'instructions' => $this->instructions,
            'sort_order' => $this->sort_order,
        ];

        if ($this->code === 'leasing') {
            $contactMethodLabels = [
                'phone' => 'Телефон',
                'email' => 'E-mail',
                'either' => 'Телефон или e-mail',
            ];
            $contactTimeLabels = [
                'anytime' => 'Без предпочитание',
                'morning' => 'Сутрин',
                'afternoon' => 'Следобед',
                'evening' => 'Вечер',
            ];
            $resource['options'] = [
                'term_months' => array_values(config('payments.methods.leasing.allowed_terms_months', [])),
                'contact_methods' => collect(config('payments.methods.leasing.contact_methods', []))
                    ->filter(fn (string $value): bool => isset($contactMethodLabels[$value]))
                    ->map(fn (string $value): array => [
                        'value' => $value,
                        'label' => $contactMethodLabels[$value],
                    ])
                    ->values()
                    ->all(),
                'contact_time_slots' => collect(config('payments.methods.leasing.contact_time_slots', []))
                    ->filter(fn (string $value): bool => isset($contactTimeLabels[$value]))
                    ->map(fn (string $value): array => [
                        'value' => $value,
                        'label' => $contactTimeLabels[$value],
                    ])
                    ->values()
                    ->all(),
                'currency' => 'EUR',
            ];
        }

        return $resource;
    }
}
