<?php

namespace App\Domain\Review\Repositories\Contracts;

use App\Domain\Review\Models\Review;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ReviewRepositoryInterface
{
    public function find(int $id): ?Review;

    public function findByReservation(int $reservationId): ?Review;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Review;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Review $review, array $data): Review;

    /**
     * Guest-facing: published reviews only, newest first.
     *
     * @return LengthAwarePaginator<Review>
     */
    public function paginatePublishedForHotel(int $hotelId, int $perPage = 15): LengthAwarePaginator;

    /**
     * Staff-facing: every moderation state, optionally filtered.
     *
     * @return LengthAwarePaginator<Review>
     */
    public function paginateForHotel(int $hotelId, ?string $status, int $perPage = 15): LengthAwarePaginator;

    /**
     * Review counts by moderation status, plus the rating sum and rated
     * count (so a precise average can be computed at any aggregation
     * level without averaging averages), for $hotelId, submitted within
     * [$from, $to] — the reviews report's data source.
     *
     * @return array{by_status: array<string, int>, rating_sum: int, rated_count: int}
     */
    public function statsForHotel(int $hotelId, string $from, string $to): array;

    /**
     * All-time totals for a hotel's reviews: count per moderation status,
     * and the average overall rating + count over published reviews.
     *
     * @return array{by_status: array<string, int>, published_average: float|null, published_count: int}
     */
    public function totalsForHotel(int $hotelId): array;
}
