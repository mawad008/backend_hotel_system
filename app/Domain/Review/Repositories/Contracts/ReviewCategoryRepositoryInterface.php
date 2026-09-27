<?php

namespace App\Domain\Review\Repositories\Contracts;

use App\Domain\Review\Models\ReviewCategory;
use Illuminate\Support\Collection;

interface ReviewCategoryRepositoryInterface
{
    /** @return Collection<int, ReviewCategory> ordered by sort_order, id */
    public function forHotel(int $hotelId, bool $activeOnly = false): Collection;

    public function create(array $data): ReviewCategory;

    public function update(ReviewCategory $category, array $data): ReviewCategory;

    public function delete(ReviewCategory $category): void;

    public function nextSortOrder(int $hotelId): int;

    /** @param  list<int>  $orderedIds */
    public function reorder(int $hotelId, array $orderedIds): void;

    public function hasRatings(ReviewCategory $category): bool;

    /**
     * Per-category average + count over reviews in [$reviewStatus]
     * (null = every status), for the hotel's categories.
     *
     * @return array<int, array{average: float|null, ratings_count: int}>
     *                                                                    keyed by category id
     */
    public function categoryStats(int $hotelId, ?string $reviewStatus): array;
}
