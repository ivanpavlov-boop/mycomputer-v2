<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PcBuild extends Model
{
    public const STATUSES = ['draft', 'saved', 'shared', 'ordered'];

    protected $fillable = ['user_id', 'session_id', 'name', 'description', 'total_price', 'status'];

    protected function casts(): array
    {
        return ['total_price' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PcBuildItem::class);
    }
}
