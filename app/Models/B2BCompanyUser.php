<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class B2BCompanyUser extends Model
{
    public const ROLES = ['owner', 'buyer', 'accountant', 'manager'];

    public const STATUSES = ['active', 'inactive', 'pending'];

    protected $table = 'b2b_company_users';

    protected $fillable = ['b2b_company_id', 'user_id', 'role', 'status'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(B2BCompany::class, 'b2b_company_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
