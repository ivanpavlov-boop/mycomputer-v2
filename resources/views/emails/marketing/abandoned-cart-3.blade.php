<h1>Последно напомняне за количката</h1>

<p>Някои продукти могат да се изчерпят или цените да се променят. Количката ви е запазена още малко.</p>
<p>Бъдеща отстъпка или промо код може да бъде добавен тук като placeholder.</p>

@include('emails.marketing.partials.abandoned-cart-summary', [
    'items' => $items ?? [],
    'cartTotal' => $cartTotal ?? $record->cart_total,
    'recoveryUrl' => $recoveryUrl,
    'unsubscribeUrl' => $unsubscribeUrl,
    'supportContact' => $supportContact,
])
