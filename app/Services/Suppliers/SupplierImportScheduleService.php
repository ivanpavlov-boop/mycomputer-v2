<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class SupplierImportScheduleService
{
    public function dueSuppliers(?Carbon $now = null): Collection
    {
        $now ??= now();

        return Supplier::query()
            ->where('status', 'active')
            ->where('import_enabled', true)
            ->where('schedule_enabled', true)
            ->where('schedule_type', '!=', 'manual_only')
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_import_at')->orWhere('next_import_at', '<=', $now);
            })
            ->orderBy('next_import_at')
            ->orderBy('priority')
            ->get();
    }

    public function isDue(Supplier $supplier, ?Carbon $now = null): bool
    {
        $now ??= now();

        return $supplier->status === 'active'
            && $supplier->import_enabled
            && $supplier->schedule_enabled
            && $supplier->schedule_type !== 'manual_only'
            && ($supplier->next_import_at === null || $supplier->next_import_at->lte($now));
    }

    public function nextRunAt(Supplier $supplier, ?Carbon $from = null): ?Carbon
    {
        $from ??= now();

        if (! $supplier->schedule_enabled || $supplier->schedule_type === 'manual_only') {
            return null;
        }

        $timezone = $supplier->timezone ?: config('app.timezone', 'UTC');
        $local = $from->copy()->timezone($timezone);

        return match ($supplier->schedule_type) {
            'hourly' => $from->copy()->addHour()->startOfMinute(),
            'daily' => $this->dailyRun($supplier, $local)->timezone('UTC'),
            'twice_daily' => $this->twiceDailyRun($supplier, $local)->timezone('UTC'),
            'custom' => $this->twiceDailyRun($supplier, $local)->timezone('UTC'),
            default => null,
        };
    }

    private function dailyRun(Supplier $supplier, Carbon $local): Carbon
    {
        $time = $supplier->morning_import_time ?: '06:00';
        $candidate = $this->atLocalTime($local, $time);

        return $candidate->gt($local) ? $candidate : $candidate->addDay();
    }

    private function twiceDailyRun(Supplier $supplier, Carbon $local): Carbon
    {
        $times = array_filter([
            $supplier->morning_import_time ?: '06:00',
            $supplier->evening_import_time ?: '19:00',
        ]);

        sort($times);

        foreach ($times as $time) {
            $candidate = $this->atLocalTime($local, $time);

            if ($candidate->gt($local)) {
                return $candidate;
            }
        }

        return $this->atLocalTime($local->copy()->addDay(), $times[0]);
    }

    private function atLocalTime(Carbon $date, string $time): Carbon
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, 0);

        return $date->copy()->setTime((int) $hour, (int) $minute);
    }
}
