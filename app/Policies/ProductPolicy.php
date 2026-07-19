<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy extends PermissionPolicy
{
    protected string $permission = 'manage products';

    public function submitForReview(User $user, Product $product): bool
    {
        return $user->canEditProductContent();
    }

    public function requestChanges(User $user, Product $product): bool
    {
        return $user->canApproveProducts();
    }

    public function approve(User $user, Product $product): bool
    {
        return $user->canApproveProducts();
    }

    public function publish(User $user, Product $product): bool
    {
        return $user->canPublishProducts();
    }

    public function hide(User $user, Product $product): bool
    {
        return $user->canPublishProducts();
    }
}
