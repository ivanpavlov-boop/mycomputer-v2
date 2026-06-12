<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierImportRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier' => [
                'id' => $this->supplier?->id,
                'company_name' => $this->supplier?->company_name,
            ],
            'feed' => [
                'id' => $this->feed?->id,
                'name' => $this->feed?->feed_name,
                'type' => $this->feed?->feed_type,
            ],
            'trigger_type' => $this->trigger_type,
            'import_type' => $this->import_type,
            'status' => $this->status,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'duration_seconds' => $this->duration_seconds,
            'metrics' => [
                'products_seen' => $this->products_seen,
                'products_created' => $this->products_created,
                'products_updated' => $this->products_updated,
                'products_skipped' => $this->products_skipped,
                'products_failed' => $this->products_failed,
                'products_out_of_stock' => $this->products_out_of_stock,
                'products_needing_review' => $this->products_needing_review,
                'attributes_mapped' => $this->attributes_mapped,
                'attributes_unmapped' => $this->attributes_unmapped,
                'availability_mapped' => $this->availability_mapped,
                'availability_unmapped' => $this->availability_unmapped,
            ],
            'warnings' => $this->warnings ?? [],
            'errors' => $this->errors ?? [],
            'report' => $this->report,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
