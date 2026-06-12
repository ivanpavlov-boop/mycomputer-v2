<h1>Забравихте продукти в количката</h1>

<p>Здравейте{{ $record->user?->first_name ? ', '.$record->user->first_name : '' }},</p>
<p>Вашата количка в mycomputer.bg все още ви очаква.</p>

@include('emails.marketing.partials.abandoned-cart-summary', [
    'items' => $items ?? [],
    'cartTotal' => $cartTotal ?? $record->cart_total,
    'recoveryUrl' => $recoveryUrl,
    'unsubscribeUrl' => $unsubscribeUrl,
    'supportContact' => $supportContact,
])
