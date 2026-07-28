<?php

namespace App\Services\Leasing;

use App\Events\LeasingApplicationSubmitted;
use App\Models\LeasingApplication;
use App\Models\LeasingApplicationActivity;
use App\Models\Order;
use App\Models\PaymentTransaction;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeasingApplicationService
{
    private const PAYLOAD_KEYS = [
        'term_months',
        'down_payment',
        'contact_method',
        'contact_time',
        'note',
        'consent',
    ];

    /**
     * @param  array<string, mixed>  $validatedPayload
     * @return array<string, mixed>
     */
    public function validateCheckoutPayload(Request $request, array $validatedPayload): array
    {
        $isLeasing = ($validatedPayload['payment_method'] ?? null) === 'leasing';
        $hasLeasingPayload = $request->exists('leasing_application');

        if (! $isLeasing) {
            if ($hasLeasingPayload) {
                throw ValidationException::withMessages([
                    'leasing_application' => 'Данни за покупка на изплащане са позволени само при избран този начин на плащане.',
                ]);
            }

            return $validatedPayload;
        }

        $input = $request->input('leasing_application');

        if (! is_array($input)) {
            throw ValidationException::withMessages([
                'leasing_application' => 'Попълнете данните за покупка на изплащане.',
            ]);
        }

        $unexpected = array_diff(array_keys($input), self::PAYLOAD_KEYS);

        if ($unexpected !== []) {
            throw ValidationException::withMessages(collect($unexpected)
                ->mapWithKeys(fn (string $key): array => [
                    "leasing_application.{$key}" => 'Това поле не е позволено.',
                ])
                ->all());
        }

        $terms = array_map('intval', config('payments.methods.leasing.allowed_terms_months', []));
        $contactMethods = config('payments.methods.leasing.contact_methods', []);
        $contactTimes = config('payments.methods.leasing.contact_time_slots', []);
        $noteMaxLength = (int) config('payments.methods.leasing.customer_note_max_length', 1000);
        $validator = Validator::make(
            ['leasing_application' => $input],
            [
                'leasing_application.term_months' => ['required', 'integer', Rule::in($terms)],
                'leasing_application.down_payment' => [
                    'required',
                    'numeric',
                    'min:0',
                    function (string $attribute, mixed $value, Closure $fail): void {
                        if (preg_match('/\A\d+(?:\.\d{1,2})?\z/', (string) $value) !== 1) {
                            $fail('Желаната първоначална вноска трябва да е с най-много два знака след десетичната точка.');
                        }
                    },
                ],
                'leasing_application.contact_method' => ['required', 'string', Rule::in($contactMethods)],
                'leasing_application.contact_time' => ['nullable', 'string', Rule::in($contactTimes)],
                'leasing_application.note' => ['nullable', 'string', 'max:'.$noteMaxLength],
                'leasing_application.consent' => ['required', 'accepted'],
            ],
            [
                'leasing_application.term_months.required' => 'Изберете желан срок.',
                'leasing_application.term_months.integer' => 'Желаният срок е невалиден.',
                'leasing_application.term_months.in' => 'Избраният срок не се поддържа.',
                'leasing_application.down_payment.required' => 'Въведете желана първоначална вноска.',
                'leasing_application.down_payment.numeric' => 'Желаната първоначална вноска трябва да е число.',
                'leasing_application.down_payment.min' => 'Желаната първоначална вноска не може да е отрицателна.',
                'leasing_application.contact_method.required' => 'Изберете предпочитан начин за контакт.',
                'leasing_application.contact_method.in' => 'Избраният начин за контакт не се поддържа.',
                'leasing_application.contact_time.in' => 'Избраното време за контакт не се поддържа.',
                'leasing_application.note.max' => "Коментарът може да съдържа най-много {$noteMaxLength} знака.",
                'leasing_application.consent.required' => 'Необходимо е съгласие за обработване на заявката.',
                'leasing_application.consent.accepted' => 'Необходимо е съгласие за обработване на заявката.',
            ],
        );

        $validated = $validator->validate()['leasing_application'];

        return $validatedPayload + [
            'leasing_application' => [
                'term_months' => (int) $validated['term_months'],
                'down_payment' => number_format((float) $validated['down_payment'], 2, '.', ''),
                'contact_method' => $validated['contact_method'],
                'contact_time' => $validated['contact_time'] ?? null,
                'note' => isset($validated['note'])
                    ? trim(strip_tags($validated['note']))
                    : null,
                'consent' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $checkoutPayload
     */
    public function assertDownPaymentWithinTotal(array $checkoutPayload, float $trustedGrandTotal): void
    {
        if (($checkoutPayload['payment_method'] ?? null) !== 'leasing') {
            return;
        }

        $downPaymentCents = (int) round(((float) $checkoutPayload['leasing_application']['down_payment']) * 100);
        $grandTotalCents = (int) round($trustedGrandTotal * 100);

        if ($downPaymentCents > $grandTotalCents) {
            throw ValidationException::withMessages([
                'leasing_application.down_payment' => 'Желаната първоначална вноска не може да надвишава общата сума на поръчката.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $checkoutPayload
     */
    public function createForOrder(
        Order $order,
        PaymentTransaction $transaction,
        array $checkoutPayload,
    ): ?LeasingApplication {
        if (($checkoutPayload['payment_method'] ?? null) !== 'leasing') {
            return null;
        }

        $preferences = $checkoutPayload['leasing_application'];
        $submittedAt = now();
        $application = LeasingApplication::query()->create([
            'reference' => $this->reference(),
            'order_id' => $order->getKey(),
            'status' => LeasingApplication::STATUS_SUBMITTED,
            'requested_term_months' => $preferences['term_months'],
            'requested_down_payment' => $preferences['down_payment'],
            'currency' => strtoupper($transaction->currency),
            'preferred_contact_method' => $preferences['contact_method'],
            'preferred_contact_time' => $preferences['contact_time'],
            'customer_note' => filled($preferences['note']) ? $preferences['note'] : null,
            'contact_consent_at' => $submittedAt,
            'consent_version' => config('payments.methods.leasing.consent_version'),
            'submitted_at' => $submittedAt,
        ]);

        $application->activities()->create([
            'event_type' => LeasingApplicationActivity::EVENT_SUBMITTED,
            'to_status' => LeasingApplication::STATUS_SUBMITTED,
            'created_at' => $submittedAt,
        ]);

        LeasingApplicationSubmitted::dispatch($application->getKey());

        return $application;
    }

    private function reference(): string
    {
        do {
            $reference = 'LA-'.Str::upper(Str::random(16));
        } while (LeasingApplication::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
