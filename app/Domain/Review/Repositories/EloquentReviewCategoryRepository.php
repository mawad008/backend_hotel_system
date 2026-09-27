<?php

namespace App\Domain\Review\Repositories;

use App\Domain\Review\Models\ReviewCategory;
use App\Domain\Review\Models\ReviewCategoryRating;
use App\Domain\Review\Repositories\Contracts\ReviewCategoryRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentReviewCategoryRepository implements ReviewCategoryRepositoryInterface
{
    public function forHotel(int $hotelId, bool $activeOnly = false): Collection
    {
        return ReviewCategory::query()
            ->where('hotel_id', $hotelId)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function create(array $data): ReviewCategory
    {
        return ReviewCategory::create($data)->refresh();
    }

    public function update(ReviewCategory $category, array $data): ReviewCategory
    {
        $category->update($data);

        return $category->refresh();
    }

    public function delete(ReviewCategory $category): void
    {
        $category->delete();
    }

    public function nextSortOrder(int $hotelId): int
    {
        $max = ReviewCategory::query()->where('hotel_id', $hotelId)->max('sort_order');

        return $max === null ? 0 : ((int) $max) + 1;
    }

    public function reorder(int $hotelId, array $orderedIds): void
    {
        DB::transaction(function () use ($hotelId, $orderedIds): void {
            foreach (array_values($orderedIds) as $position => $id) {
                ReviewCategory::query()
                    ->where('hotel_id', $hotelId)
                    ->whereKey($id)
                    ->update(['sort_order' => $position]);
            }
        });
    }

    public function hasRatings(ReviewCategory $category): bool
    {
        return ReviewCategoryRating::query()
            ->where('review_category_id', $category->id)
            ->exists();
    }

    public function categoryStats(int $hotelId, ?string $reviewStatus): array
    {
        $rows = ReviewCategoryRating::query()
            ->join('review_categories', 'review_categories.id', '=', 'review_category_ratings.review_category_id')
            ->join('reviews', 'reviews.id', '=', 'review_category_ratings.review_id')
            ->where('review_categories.hotel_id', $hotelId)
            ->when($reviewStatus !== null, fn ($q) => $q->where('reviews.status', $reviewStatus))
            ->groupBy('review_category_ratings.review_category_id')
            ->selectRaw('review_category_ratings.review_category_id as category_id, AVG(review_category_ratings.rating) as average, COUNT(*) as ratings_count')
            ->get();

        $stats = [];
        foreach ($rows as $row) {
            $stats[(int) $row->category_id] = [
                'average' => $row->average === null ? null : round((float) $row->average, 2),
                'ratings_count' => (int) $row->ratings_count,
            ];
        }

        return $stats;
    }
}
