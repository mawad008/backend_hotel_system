<?php

namespace App\Domain\StayServices\Repositories\Contracts;

use App\Domain\StayServices\Models\ServiceReview;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ServiceReviewRepositoryInterface
{
    public function find(int $id): ?ServiceReview;

    public function findByServiceOrder(int $serviceOrderId): ?ServiceReview;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ServiceReview;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ServiceReview $review, array $data): ServiceReview;

    /**
     * Staff-facing: every moderation state for one hotel, optionally
     * filtered by service and/or status.
     *
     * @return LengthAwarePaginator<ServiceReview>
     */
    public function paginateForHotel(int $hotelId, ?int $serviceId, ?string $status, int $perPage = 15): LengthAwarePaginator;
}
