<?php

namespace App\Domain\Review\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Review\Exceptions\ReviewNotAllowedException;
use App\Domain\Review\Models\Review;
use App\Domain\Review\Models\ReviewCategory;
use App\Domain\Review\Repositories\Contracts\ReviewCategoryRepositoryInterface;
use App\Domain\Review\Repositories\Contracts\ReviewRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Dynamic, per-hotel review categories (dashboard-managed) and the hotel's
 * rating analytics built from them.
 *
 * ── Invariants ──
 * - Categories belong to exactly one hotel; nothing about their number or
 *   names is assumed in code.
 * - Deactivation hides a category from *new* reviews only.
 * - A category with any guest rating cannot be hard deleted
 *   (`category_in_use`) — history must stay intact; deactivate instead.
 * - Averages are computed live from `review_category_ratings`, grouped by
 *   category id (the stable identity), over **published** reviews — the same
 *   rule as the hotel's public overall rating.
 */
class ReviewCategoryService
{
    public function __construct(
        private readonly ReviewCategoryRepositoryInterface $categories,
        private readonly ReviewRepositoryInterface $reviews,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @return Collection<int, ReviewCategory> */
    public function forHotel(Hotel $hotel, bool $activeOnly = false): Collection
    {
        return $this->categories->forHotel($hotel->id, $activeOnly);
    }

    public function create(Hotel $hotel, array $data, ?User $actor = null): ReviewCategory
    {
        $category = $this->categories->create([
            ...$data,
            'hotel_id' => $hotel->id,
            'sort_order' => $data['sort_order'] ?? $this->categories->nextSortOrder($hotel->id),
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->auditLogger->record($actor, 'review_category.created', $category,
            after: $category->only(['name', 'name_ar', 'name_en', 'is_active']), hotelId: $hotel->id);

        return $category;
    }

    public function update(ReviewCategory $category, array $data, ?User $actor = null): ReviewCategory
    {
        $before = $category->only(['name', 'name_ar', 'name_en', 'description', 'icon', 'sort_order']);
        $updated = $this->categories->update($category, $data);

        $this->auditLogger->record($actor, 'review_category.updated', $updated,
            before: $before,
            after: $updated->only(['name', 'name_ar', 'name_en', 'description', 'icon', 'sort_order']),
            hotelId: $updated->hotel_id);

        return $updated;
    }

    public function setActive(ReviewCategory $category, bool $active, ?User $actor = null): ReviewCategory
    {
        $updated = $this->categories->update($category, ['is_active' => $active]);

        $this->auditLogger->record($actor,
            $active ? 'review_category.activated' : 'review_category.deactivated',
            $updated, after: ['is_active' => $active], hotelId: $updated->hotel_id);

        return $updated;
    }

    /**
     * @param  list<int>  $orderedIds  every category id of the hotel, once each
     *
     * @throws ReviewNotAllowedException `reorder_mismatch`
     */
    public function reorder(Hotel $hotel, array $orderedIds, ?User $actor = null): Collection
    {
        $existing = $this->categories->forHotel($hotel->id)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $given = collect($orderedIds)->map(fn ($id) => (int) $id);

        if ($given->duplicates()->isNotEmpty() || $given->sort()->values()->all() !== $existing) {
            throw ReviewNotAllowedException::reorderMismatch();
        }

        $this->categories->reorder($hotel->id, $given->all());
        $this->auditLogger->record($actor, 'review_category.reordered', $hotel,
            after: ['order' => $given->all()], hotelId: $hotel->id);

        return $this->categories->forHotel($hotel->id);
    }

    /** @throws ReviewNotAllowedException `category_in_use` */
    public function delete(ReviewCategory $category, ?User $actor = null): void
    {
        if ($this->categories->hasRatings($category)) {
            throw ReviewNotAllowedException::categoryInUse();
        }

        $this->auditLogger->record($actor, 'review_category.deleted', $category,
            before: $category->only(['name', 'name_ar', 'name_en']), hotelId: $category->hotel_id);
        $this->categories->delete($category);
    }

    /**
     * The guest-facing rating summary: the published overall average/count
     * and every **active** category with its published average and count
     * (average `null` while a category has no ratings yet).
     *
     * @return array{average: float|null, count: int, categories: list<array{category: ReviewCategory, average: float|null, ratings_count: int}>}
     */
    public function publicSummary(Hotel $hotel): array
    {
        $totals = $this->reviews->totalsForHotel($hotel->id);
        $stats = $this->categories->categoryStats($hotel->id, Review::STATUS_PUBLISHED);

        return [
            'average' => $totals['published_average'],
            'count' => $totals['published_count'],
            'categories' => $this->categories->forHotel($hotel->id, activeOnly: true)
                ->map(fn (ReviewCategory $c) => [
                    'category' => $c,
                    'average' => $stats[$c->id]['average'] ?? null,
                    'ratings_count' => $stats[$c->id]['ratings_count'] ?? 0,
                ])->values()->all(),
        ];
    }

    /**
     * Dashboard analytics for one hotel: review totals per moderation status,
     * the published overall average, and **every** category (active or not)
     * with its published average and rating count. Built live from the
     * database, so a newly added category appears automatically.
     *
     * @return array{total_reviews: int, by_status: array<string, int>, average: float|null, published_count: int, categories: list<array{category: ReviewCategory, average: float|null, ratings_count: int}>}
     */
    public function analytics(Hotel $hotel): array
    {
        $totals = $this->reviews->totalsForHotel($hotel->id);
        $stats = $this->categories->categoryStats($hotel->id, Review::STATUS_PUBLISHED);

        return [
            'total_reviews' => array_sum($totals['by_status']),
            'by_status' => $totals['by_status'],
            'average' => $totals['published_average'],
            'published_count' => $totals['published_count'],
            'categories' => $this->categories->forHotel($hotel->id)
                ->map(fn (ReviewCategory $c) => [
                    'category' => $c,
                    'average' => $stats[$c->id]['average'] ?? null,
                    'ratings_count' => $stats[$c->id]['ratings_count'] ?? 0,
                ])->values()->all(),
        ];
    }
}
