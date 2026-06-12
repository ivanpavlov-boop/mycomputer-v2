<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ServiceTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceTicketStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'ticket_type' => ['required', Rule::in(ServiceTicket::TYPES)],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'priority' => ['nullable', Rule::in(ServiceTicket::PRIORITIES)],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'purchased_at' => ['nullable', 'date'],
        ];
    }
}
