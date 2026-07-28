<x-mail::message>
# Нова заявка за покупка на изплащане

Референция: **{{ $application->reference }}**

Поръчка: **{{ $order->order_number }}**

Клиент: **{{ $order->customer_name }}**

Телефон: **{{ $order->customer_phone }}**

E-mail: **{{ $order->customer_email }}**

Обща сума: **{{ $order->grand_total }} {{ $application->currency }}**

Желан срок: **{{ $application->requested_term_months }} месеца**

Желана първоначална вноска: **{{ $application->requested_down_payment }} {{ $application->currency }}**

Предпочитан контакт: **{{ $application->preferred_contact_method }}**

Предпочитано време: **{{ $application->preferred_contact_time ?: 'Без предпочитание' }}**

Коментар: **{{ $application->customer_note ?: 'Няма' }}**
</x-mail::message>
