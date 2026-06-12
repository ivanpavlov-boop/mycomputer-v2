<table cellpadding="6" cellspacing="0" style="width: 100%; border-collapse: collapse;">
    <thead>
        <tr>
            <th align="left">Продукт</th>
            <th align="center">Количество</th>
            <th align="right">Цена</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($items as $item)
            <tr>
                <td>{{ $item['name'] ?? 'Продукт' }}<br><small>{{ $item['sku'] ?? '' }}</small></td>
                <td align="center">{{ $item['quantity'] ?? 1 }}</td>
                <td align="right">{{ number_format((float) ($item['total_price'] ?? $item['unit_price'] ?? 0), 2) }} лв.</td>
            </tr>
        @endforeach
    </tbody>
</table>

<p><strong>Общо: {{ number_format((float) $cartTotal, 2) }} лв.</strong></p>
<p><a href="{{ $recoveryUrl }}">Възстановете количката</a></p>
<p>Нужда от помощ? Пишете ни на {{ $supportContact }}.</p>
<p><small><a href="{{ $unsubscribeUrl }}">Отписване от напомняния</a></small></p>
