<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SeoMetadata extends Model
{
    protected $fillable = [
        'metadatable_type',
        'metadatable_id',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'canonical_url',
        'og_title',
        'og_description',
        'og_image',
        'schema_type',
        'schema_data',
    ];

    protected function casts(): array
    {
        return ['schema_data' => 'array'];
    }

    public function metadatable(): MorphTo
    {
        return $this->morphTo();
    }
}
