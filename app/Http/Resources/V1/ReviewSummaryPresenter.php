<?php

namespace App\Http\Resources\V1;

use App\Domain\Review\Models\ReviewCategory;

/**
 * Serialises ReviewCategoryService's summaries — the per-category rows are
 * built from whatever categories the hotel has in the database.
 */
final class ReviewSummaryPresenter
{
    /**
     * @param  list<array{category: ReviewCategory, average: float|null, ratings_count: int}>  $rows
     * @return list<array<string, mixed>>
     */
    public static function categories(array $rows, bool $withAdminFields = false): array
    {
        return array_map(function (array $row) use ($withAdminFields): array {
            $category = $row['category'];

            return [
                'id' => $category->id,
                'label' => $category->localizedName(),
                'icon' => $category->icon,
                'average' => $row['average'],
                'ratings_count' => $row['ratings_count'],
                ...($withAdminFields ? [
                    'name' => $category->name,
                    'is_active' => $category->is_active,
                    'sort_order' => $category->sort_order,
                ] : []),
            ];
        }, $rows);
    }
}
