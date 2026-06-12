<h1>Вашата количка ви очаква</h1>

<p>Запазихме продуктите, за да можете лесно да завършите поръчката.</p>
<p>При въпроси за съвместимост, наличност или доставка можете да се свържете с нас.</p>

@include('emails.marketing.partials.abandoned-cart-summary', [
    'items' => $items ?? [],
    'cartTotal' => $cartTotal ?? $record->cart_total,
    'recoveryUrl' => $recoveryUrl,
    'unsubscribeUrl' => $unsubscribeUrl,
    'supportContact' => $supportContact,
])
