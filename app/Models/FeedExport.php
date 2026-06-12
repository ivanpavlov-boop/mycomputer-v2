<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedExport extends Model
{
    public const TYPES = ['google_merchant', 'facebook_catalog', 'custom_xml'];

    public const STATUSES = ['pending', 'generated', 'failed'];

    protected $fillable = [
        'feed_type',
        'status',
        'file_path',
        'products_count',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
        ];
    }
}
