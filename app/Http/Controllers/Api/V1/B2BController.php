<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\B2BApplyRequest;
use App\Http\Resources\B2BCompanyResource;
use App\Http\Resources\B2BCompanyUserResource;
use App\Services\B2B\B2BCompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class B2BController extends Controller
{
    public function status(Request $request, B2BCompanyService $companies): JsonResponse
    {
        $company = $companies->companyForUser($request->user());

        return response()->json([
            'data' => [
                'has_company' => $company !== null,
                'company' => $company ? new B2BCompanyResource($company) : null,
            ],
        ]);
    }

    public function apply(B2BApplyRequest $request, B2BCompanyService $companies): B2BCompanyResource
    {
        return new B2BCompanyResource($companies->apply($request->user(), $request->validated()));
    }

    public function company(Request $request, B2BCompanyService $companies): B2BCompanyResource|JsonResponse
    {
        $company = $companies->companyForUser($request->user());

        if (! $company) {
            return response()->json(['message' => 'No B2B company account found.'], 404);
        }

        return new B2BCompanyResource($company);
    }

    public function updateCompany(Request $request, B2BCompanyService $companies): B2BCompanyResource|JsonResponse
    {
        $company = $companies->companyForUser($request->user());
        if (! $company) {
            return response()->json(['message' => 'No B2B company account found.'], 404);
        }

        $company->update($request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'website' => ['nullable', 'url', 'max:255'],
            'billing_address' => ['nullable', 'string', 'max:2000'],
            'shipping_address' => ['nullable', 'string', 'max:2000'],
        ]));

        return new B2BCompanyResource($company->fresh('users.user'));
    }

    public function users(Request $request, B2BCompanyService $companies): JsonResponse
    {
        $company = $companies->companyForUser($request->user());
        abort_unless($company, 404);

        return response()->json(['data' => B2BCompanyUserResource::collection($company->users()->with('user')->get())]);
    }

    public function invite(Request $request, B2BCompanyService $companies): JsonResponse
    {
        $company = $companies->companyForUser($request->user());
        abort_unless($company, 404);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'in:buyer,accountant,manager'],
        ]);

        return response()->json([
            'message' => 'Invitation placeholder recorded for future email invite flow.',
            'data' => ['company_id' => $company->id, 'email' => $data['email'], 'role' => $data['role']],
        ], 202);
    }
}
