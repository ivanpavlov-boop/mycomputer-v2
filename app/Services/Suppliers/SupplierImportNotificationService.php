<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierImportRun;
use App\Services\Email\EmailMarketingService;

class SupplierImportNotificationService
{
    public function notifyIfNeeded(Supplier $supplier, SupplierImportRun $run): void
    {
        if ($run->error_count === 0 && $run->warning_count === 0) {
            return;
        }

        if ($supplier->last_import_notification_at?->gt(now()->subHours(2))) {
            return;
        }

        $email = config('mail.from.address') ?: config('app.support_email');

        if (! $email) {
            return;
        }

        app(EmailMarketingService::class)->queue($email, 'supplier_import_alert', [
            'supplier' => $supplier->company_name,
            'status' => $run->status,
            'warnings' => $run->warnings ?? [],
            'errors' => $run->errors ?? [],
            'report' => $run->report ?? [],
        ], 'Supplier import alert: '.$supplier->company_name);

        $supplier->update(['last_import_notification_at' => now()]);
    }
}
