<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentMethodUnavailableException;
use App\Exceptions\PaymentRetryNotAllowedException;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Support\Str;

class PaymentActionPresentationService
{
    public function __construct(
        private readonly PaymentMethodAvailabilityService $availability,
        private readonly PaymentRetryPolicy $retryPolicy,
        private readonly PaymentRedirectPolicy $redirects,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forCheckoutConfirmation(Order $order): array
    {
        return $this->present(
            $order,
            retryAuthorized: $order->user_id === null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function forAccountOrder(Order $order, ?User $user): array
    {
        return $this->present(
            $order,
            retryAuthorized: $user !== null
                && $order->user_id !== null
                && (int) $order->user_id === (int) $user->getKey(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function forPaymentAttempt(PaymentAttemptResult $result): array
    {
        $order = $result->attempt->order()->firstOrFail();

        return $this->present(
            $order,
            retryAuthorized: true,
            transaction: $result->transaction,
            attempt: $result->attempt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(
        Order $order,
        bool $retryAuthorized,
        ?PaymentTransaction $transaction = null,
        ?PaymentAttempt $attempt = null,
    ): array {
        $order->loadMissing([
            'paymentTransactions.method.provider',
            'paymentAttempts',
        ]);

        $transaction ??= $order->paymentTransactions
            ->sortByDesc('id')
            ->first();
        $transaction?->loadMissing(['method.provider']);
        $attempt ??= $order->paymentAttempts
            ->sortByDesc('id')
            ->first();
        $method = $transaction?->method;
        $status = $transaction?->status ?? $order->payment_status;
        $currency = is_string($transaction?->currency) && strlen($transaction->currency) === 3
            ? strtoupper($transaction->currency)
            : 'EUR';
        $instructions = $this->safeInstructions(
            data_get($transaction?->raw_response, 'instructions')
                ?? $method?->instructions,
        );

        if ($status === 'paid') {
            return $this->result(
                state: 'paid',
                label: 'Платено',
                message: 'Плащането е потвърдено.',
                currency: $currency,
                instructions: $instructions,
            );
        }

        if ($status === 'refunded') {
            return $this->result(
                state: 'refunded',
                label: 'Възстановено плащане',
                message: 'Плащането е възстановено.',
                currency: $currency,
                instructions: $instructions,
            );
        }

        if ($attempt?->status === PaymentAttempt::STATUS_INDETERMINATE) {
            return $this->result(
                state: 'indeterminate',
                label: 'Потвърждението за плащането се очаква',
                message: 'Не създавайте нова поръчка. Опитайте отново само чрез същия платежен опит.',
                currency: $currency,
                instructions: $instructions,
            );
        }

        if ($attempt?->status === PaymentAttempt::STATUS_PROCESSING) {
            return $this->result(
                state: 'pending',
                label: 'Плащането се обработва',
                message: 'Платежният опит се обработва. Изчакайте и опитайте отново.',
                currency: $currency,
                instructions: $instructions,
            );
        }

        if ($order->payment_method === 'cash_on_delivery') {
            return $this->result(
                state: 'cash_on_delivery',
                label: 'Плащане при доставка',
                message: 'Ще заплатите сумата при получаване на поръчката.',
                currency: $currency,
            );
        }

        if ($order->payment_method === 'bank_transfer') {
            return $this->result(
                state: 'bank_transfer',
                label: 'Очаква се банков превод',
                message: 'Следвайте банковите инструкции към поръчката.',
                currency: $currency,
                instructions: $instructions,
            );
        }

        if ($order->payment_method === 'leasing') {
            return $this->result(
                state: 'leasing',
                label: 'Заявката е получена',
                message: 'Получихме Вашата заявка за покупка на изплащане. Наш служител ще се свърже с Вас.',
                currency: $currency,
            );
        }

        if (in_array($status, ['pending', 'authorized'], true)) {
            $redirectUrl = $this->approvedRedirect($transaction);
            $available = $method !== null && $this->availability->isAvailable($method);

            return $this->result(
                state: $status,
                label: $status === 'authorized'
                    ? 'Плащането е разрешено'
                    : 'Плащането се очаква',
                message: $redirectUrl !== null && $available
                    ? 'Плащането все още не е завършено.'
                    : 'Плащането се обработва. Не създавайте нова поръчка.',
                currency: $currency,
                instructions: $instructions,
                actionType: $redirectUrl !== null && $available
                    ? 'continue_payment'
                    : 'none',
                actionLabel: $redirectUrl !== null && $available
                    ? 'Продължи към плащане'
                    : null,
                redirectUrl: $redirectUrl !== null && $available
                    ? $redirectUrl
                    : null,
            );
        }

        if (in_array($status, ['failed', 'cancelled'], true)) {
            $retryAvailable = $retryAuthorized
                && $method !== null
                && $transaction !== null
                && $this->canRetry($order, $method, $transaction);

            return $this->result(
                state: $status,
                label: $status === 'cancelled'
                    ? 'Плащането е отказано'
                    : 'Плащането не е успешно',
                message: $retryAvailable
                    ? 'Можете да опитате плащането отново, без да създавате нова поръчка.'
                    : 'Свържете се с нас за съдействие.',
                currency: $currency,
                instructions: $instructions,
                actionType: $retryAvailable ? 'retry_payment' : 'none',
                actionLabel: $retryAvailable
                    ? 'Опитай плащането отново'
                    : null,
            );
        }

        return $this->result(
            state: 'unknown',
            label: 'Неизвестно състояние на плащането',
            message: 'Свържете се с нас за съдействие.',
            currency: $currency,
            instructions: $instructions,
        );
    }

    private function canRetry(
        Order $order,
        PaymentMethod $method,
        PaymentTransaction $transaction,
    ): bool {
        try {
            return $this->retryPolicy
                ->decide($order, $method, $transaction)
                ->initiateProvider;
        } catch (PaymentMethodUnavailableException|PaymentRetryNotAllowedException) {
            return false;
        }
    }

    private function approvedRedirect(?PaymentTransaction $transaction): ?string
    {
        return $this->redirects->approved(
            data_get($transaction?->raw_response, 'redirect_url'),
        );
    }

    private function safeInstructions(mixed $instructions): ?string
    {
        if (! is_string($instructions) || trim($instructions) === '') {
            return null;
        }

        return Str::limit(trim(strip_tags($instructions)), 2000, '');
    }

    /**
     * @return array<string, mixed>
     */
    private function result(
        string $state,
        string $label,
        string $message,
        string $currency,
        ?string $instructions = null,
        string $actionType = 'none',
        ?string $actionLabel = null,
        ?string $redirectUrl = null,
    ): array {
        return [
            'state' => $state,
            'status_label' => $label,
            'message' => $message,
            'action' => [
                'type' => $actionType,
                'label' => $actionLabel,
                'available' => $actionType !== 'none',
            ],
            'redirect_url' => $redirectUrl,
            'instructions' => $instructions,
            'currency' => $currency,
        ];
    }
}
