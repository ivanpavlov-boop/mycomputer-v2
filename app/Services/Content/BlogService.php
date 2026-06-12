<?php

namespace App\Services\Content;

use App\Models\BlogPost;
use Illuminate\Database\Eloquent\Builder;

class BlogService
{
    public function query(array $filters = []): Builder
    {
        $query = BlogPost::query()
            ->published()
            ->with(['category', 'tags', 'author'])
            ->latest('published_at');

        if (filled($filters['category'] ?? null)) {
            $query->whereHas('category', fn ($query) => $query->where('slug', $filters['category'])->active());
        }

        if (filled($filters['tag'] ?? null)) {
            $query->whereHas('tags', fn ($query) => $query->where('slug', $filters['tag']));
        }

        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];
            $query->where(fn ($query) => $query
                ->where('title', 'like', "%{$search}%")
                ->orWhere('excerpt', 'like', "%{$search}%"));
        }

        return $query;
    }

    public function incrementViews(BlogPost $post): void
    {
        $post->increment('views_count');
    }
}
