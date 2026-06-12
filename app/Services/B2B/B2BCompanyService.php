<?php

namespace App\Services\B2B;

use App\Models\B2BCompany;
use App\Models\User;
use App\Services\Email\EmailMarketingService;
use Illuminate\Support\Facades\DB;

class B2BCompanyService
{
    public function __construct(
        private readonly EmailMarketingService $emailMarketing,
    ) {}

    public function apply(User $user, array $data): B2BCompany
    {
        return DB::transaction(function () use ($user, $data): B2BCompany {
            $company = B2BCompany::query()->create([
                'name' => $data['company_name'],
                'vat_number' => $data['vat_number'],
                'mol' => $data['mol'] ?? null,
                'email' => $data['email'] ?? $user->email,
                'phone' => $data['phone'] ?? $user->phone,
                'billing_address' => $data['billing_address'],
                'shipping_address' => $data['shipping_address'] ?? $data['billing_address'],
                'status' => 'inactive',
                'approval_status' => 'pending',
            ]);

            $company->users()->create([
                'user_id' => $user->id,
                'role' => 'owner',
                'status' => 'active',
            ]);

            $user->assignRole('b2b_customer');
            $this->emailMarketing->queue($user->email, 'b2b_application_submitted', ['company' => $company, 'user' => $user]);

            return $company->load('users.user');
        });
    }

    public function approve(B2BCompany $company, User $admin): void
    {
        $company->update([
            'status' => 'active',
            'approval_status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $admin->id,
        ]);

        $company->users()->with('user')->get()->each(function ($companyUser): void {
            if ($companyUser->user) {
                $this->emailMarketing->queue($companyUser->user->email, 'b2b_application_approved', ['company' => $companyUser->company]);
            }
        });
    }

    public function reject(B2BCompany $company, ?string $notes = null): void
    {
        $company->update([
            'status' => 'inactive',
            'approval_status' => 'rejected',
            'notes' => $notes ?: $company->notes,
        ]);

        $company->users()->with('user')->get()->each(function ($companyUser): void {
            if ($companyUser->user) {
                $this->emailMarketing->queue($companyUser->user->email, 'b2b_application_rejected', ['company' => $companyUser->company]);
            }
        });
    }

    public function companyForUser(User $user): ?B2BCompany
    {
        return B2BCompany::query()
            ->whereHas('users', fn ($query) => $query->where('user_id', $user->id)->where('status', 'active'))
            ->with('users.user')
            ->orderByDesc('id')
            ->first();
    }
}
