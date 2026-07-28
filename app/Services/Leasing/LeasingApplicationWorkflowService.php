<?php

namespace App\Services\Leasing;

use App\Models\LeasingApplication;
use App\Models\LeasingApplicationActivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeasingApplicationWorkflowService
{
    private const TRANSITIONS = [
        LeasingApplication::STATUS_SUBMITTED => [
            LeasingApplication::STATUS_CONTACT_PENDING,
            LeasingApplication::STATUS_CUSTOMER_CANCELLED,
            LeasingApplication::STATUS_EXPIRED,
        ],
        LeasingApplication::STATUS_CONTACT_PENDING => [
            LeasingApplication::STATUS_CONTACTED,
            LeasingApplication::STATUS_CUSTOMER_CANCELLED,
            LeasingApplication::STATUS_EXPIRED,
        ],
        LeasingApplication::STATUS_CONTACTED => [
            LeasingApplication::STATUS_SENT_TO_PARTNER,
            LeasingApplication::STATUS_CUSTOMER_CANCELLED,
            LeasingApplication::STATUS_EXPIRED,
        ],
        LeasingApplication::STATUS_SENT_TO_PARTNER => [
            LeasingApplication::STATUS_APPROVED,
            LeasingApplication::STATUS_REJECTED,
            LeasingApplication::STATUS_CUSTOMER_CANCELLED,
            LeasingApplication::STATUS_EXPIRED,
        ],
    ];

    /**
     * @return array<string, string>
     */
    public function allowedTransitionOptions(LeasingApplication $application): array
    {
        return collect(self::TRANSITIONS[$application->status] ?? [])
            ->mapWithKeys(fn (string $status): array => [$status => LeasingApplication::statusLabel($status)])
            ->all();
    }

    public function changeStatus(LeasingApplication $application, string $status, User $actor): LeasingApplication
    {
        Gate::forUser($actor)->authorize('changeStatus', $application);

        return DB::transaction(function () use ($application, $status, $actor): LeasingApplication {
            $locked = LeasingApplication::query()->lockForUpdate()->findOrFail($application->getKey());
            $allowed = self::TRANSITIONS[$locked->status] ?? [];

            if (! in_array($status, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Тази промяна на статуса не е позволена.',
                ]);
            }

            $fromStatus = $locked->status;
            $locked->update(['status' => $status]);
            $locked->activities()->create([
                'event_type' => LeasingApplicationActivity::EVENT_STATUS_CHANGED,
                'from_status' => $fromStatus,
                'to_status' => $status,
                'actor_user_id' => $actor->getKey(),
                'created_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    public function assign(LeasingApplication $application, User $assignee, User $actor): LeasingApplication
    {
        Gate::forUser($actor)->authorize('assign', $application);

        if (
            ! $assignee->isActiveAdminAccount()
            || (! $assignee->isSuperAdmin() && ! $assignee->can('manage orders'))
        ) {
            throw ValidationException::withMessages([
                'assigned_to_user_id' => 'Изберете активен служител с право да управлява поръчки.',
            ]);
        }

        return DB::transaction(function () use ($application, $assignee, $actor): LeasingApplication {
            $locked = LeasingApplication::query()->lockForUpdate()->findOrFail($application->getKey());
            $previous = $locked->assigned_to_user_id;
            $locked->update(['assigned_to_user_id' => $assignee->getKey()]);
            $locked->activities()->create([
                'event_type' => LeasingApplicationActivity::EVENT_ASSIGNED,
                'actor_user_id' => $actor->getKey(),
                'metadata' => [
                    'from_user_id' => $previous,
                    'to_user_id' => $assignee->getKey(),
                ],
                'created_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    public function addNote(LeasingApplication $application, string $note, User $actor): LeasingApplicationActivity
    {
        Gate::forUser($actor)->authorize('addNote', $application);
        $maxLength = (int) config('payments.methods.leasing.internal_note_max_length', 2000);
        $plainNote = trim(strip_tags($note));

        if ($plainNote === '' || Str::length($plainNote) > $maxLength) {
            throw ValidationException::withMessages([
                'note' => "Вътрешната бележка трябва да съдържа между 1 и {$maxLength} знака.",
            ]);
        }

        return DB::transaction(function () use ($application, $plainNote, $actor): LeasingApplicationActivity {
            $locked = LeasingApplication::query()->lockForUpdate()->findOrFail($application->getKey());

            return $locked->activities()->create([
                'event_type' => LeasingApplicationActivity::EVENT_NOTE_ADDED,
                'actor_user_id' => $actor->getKey(),
                'note' => $plainNote,
                'created_at' => now(),
            ]);
        });
    }
}
