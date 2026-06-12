<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PcCompatibilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'compatible' => $this->resource['compatible'],
            'warnings' => $this->resource['warnings'],
            'errors' => $this->resource['errors'],
            'recommendations' => $this->resource['recommendations'],
        ];
    }
}
