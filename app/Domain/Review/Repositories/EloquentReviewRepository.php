<?php

namespace App\Domain\Review\Repositories;

use App\Domain\Review\Models\Review;
use App\Domain\Review\Repositories\Contracts\ReviewRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentReviewRepository implements ReviewRepositoryInterface
{
    public function find(int $id): ?Review
    {
        return Review::query()->find($id);
    }

    public function findByReservation(int $reservationId): ?Review
    {
        return Review::query()->where('reservation_id', $reservationId)->first();
    }

    public function create(array $data): Review
    {
        return Review::create($data)->refresh();
    }

    public function update(Review $review, array $data): Review
    {
        $review->update($data);

        return $review->refresh();
    }

    public function paginatePublishedForHotel(int $hotelId, int $perPage = 15): LengthAwarePaginator
    {
        return Review::query()
            ->with('categoryRatings')
            ->where('hotel_id', $hotelId)
            ->where('status', Review::STATUS_PUBLISHED)
            ->latest()
            ->paginate($perPage);
    }

    public function paginateForHotel(int $hotelId, ?string $status, int $perPage = 15): LengthAwarePaginator
    {
        return Review::query()
            ->with(['categoryRatings', 'guest', 'hotel'])
            ->where('hotel_id', $hotelId)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate($perPage);
    }

    public function statsForHotel(int $hotelId, string $from, string $to): array
    {
        $byStatus = Review::query()
            ->where('hotel_id', $hotelId)
            ->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->all();

        $rating = Review::query()
            ->where('hotel_id', $hotelId)
            ->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->selectRaw('COALESCE(SUM(rating), 0) as rating_sum, COUNT(rating) as rated_count')
            ->first();

        return [
            'by_status' => $byStatus,
            'rating_sum' => (int) ($rating->rating_sum ?? 0),
            'rated_count' => (int) ($rating->rated_count ?? 0),
        ];
    }

    public function totalsForHotel(int $hotelId): array
    {
        $byStatus = Review::query()
            ->where('hotel_id', $hotelId)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();

        $published = Review::query()
            ->where('hotel_id', $hotelId)
            ->where('status', Review::STATUS_PUBLISHED)
            ->selectRaw('AVG(rating) as average, COUNT(*) as aggregate')
            ->first();

        return [
            'by_status' => $byStatus,
            'published_average' => $published?->average === null ? null : round((float) $published->average, 2),
            'published_count' => (int) ($published->aggregate ?? 0),
        ];
    }
}
