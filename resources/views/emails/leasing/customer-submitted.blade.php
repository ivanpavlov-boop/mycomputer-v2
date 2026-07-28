<x-mail::message>
# Получихме заявката Ви

Получихме Вашата заявка за покупка на изплащане за поръчка **{{ $order->order_number }}**.

@foreach ($order->items as $item)
- {{ $item->product_name }} × {{ $item->quantity }}
@endforeach

Обща сума: **{{ $order->grand_total }} {{ $application->currency }}**

Желан срок: **{{ $application->requested_term_months }} месеца**

Желана първоначална вноска: **{{ $application->requested_down_payment }} {{ $application->currency }}**

Наш служител ще се свърже с Вас за уточняване на условията и следващите стъпки.

Изпращането на заявката не гарантира одобрение или конкретни условия за финансиране.

Благодарим Ви,<br>
{{ config('app.name') }}
</x-mail::message>
