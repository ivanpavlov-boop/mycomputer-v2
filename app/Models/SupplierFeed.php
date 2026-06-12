<?php

namespace App\Models;

use Database\Factories\SupplierFeedFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierFeed extends Model
{
    /** @use HasFactory<SupplierFeedFactory> */
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'feed_name',
        'feed_type',
        'feed_url',
        'username',
        'password',
        'update_interval',
        'mapping',
        'last_sync_at',
        'last_error',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'mapping' => 'array',
            'last_sync_at' => 'datetime',
        ];
    }

    public function getNameAttribute(): string
    {
        return $this->feed_name;
    }

    public function getTypeAttribute(): string
    {
        return $this->feed_type;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierFeedItem::class);
    }

    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    public function importJobs(): HasMany
    {
        return $this->hasMany(ImportJob::class);
    }
}
