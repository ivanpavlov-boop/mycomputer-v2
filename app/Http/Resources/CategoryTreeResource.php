<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryTreeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'icon' => $this->icon,
            'image' => $this->image_path,
            'sort_order' => $this->sort_order,
            'children' => CategoryTreeResource::collection($this->whenLoaded('childrenRecursive')),
        ];
    }
}
