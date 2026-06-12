<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SupplierImportRunResource;
use App\Models\Supplier;
use App\Models\SupplierImportRun;
use App\Services\Suppliers\SupplierImportOrchestrator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SupplierImportController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = SupplierImportRun::query()
            ->with(['supplier', 'feed'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('trigger_type')) {
            $query->where('trigger_type', $request->string('trigger_type')->toString());
        }

        return SupplierImportRunResource::collection(
            $query->paginate(min((int) $request->integer('per_page', 25), 100))
        );
    }

    public function supplierRuns(Request $request, Supplier $supplier): AnonymousResourceCollection
    {
        return SupplierImportRunResource::collection(
            $supplier->importRuns()
                ->with(['supplier', 'feed'])
                ->latest()
                ->paginate(min((int) $request->integer('per_page', 25), 100))
        );
    }

    public function run(Supplier $supplier, SupplierImportOrchestrator $orchestrator): SupplierImportRunResource
    {
        return SupplierImportRunResource::make(
            $orchestrator->dispatch($supplier, 'manual')->fresh(['supplier', 'feed'])
        );
    }

    public function forceRun(Supplier $supplier, SupplierImportOrchestrator $orchestrator): SupplierImportRunResource
    {
        return SupplierImportRunResource::make(
            $orchestrator->dispatch($supplier, 'force', true)->fresh(['supplier', 'feed'])
        );
    }

    public function destroy(SupplierImportRun $run): Response
    {
        $run->delete();

        return response()->noContent();
    }
}
