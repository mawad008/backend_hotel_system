<?php

namespace App\Domain\StayServices\Repositories;

use App\Domain\StayServices\Models\ServiceReview;
use App\Domain\StayServices\Repositories\Contracts\ServiceReviewRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentServiceReviewRepository implements ServiceReviewRepositoryInterface
{
    public function find(int $id): ?ServiceReview
    {
        return ServiceReview::query()->find($id);
    }

    public function findByServiceOrder(int $serviceOrderId): ?ServiceReview
    {
        return ServiceReview::query()->where('service_order_id', $serviceOrderId)->first();
    }

    public function create(array $data): ServiceReview
    {
        return ServiceReview::create($data)->refresh();
    }

    public function update(ServiceReview $review, array $data): ServiceReview
    {
        $review->update($data);

        return $review->refresh();
    }

    public function paginateForHotel(int $hotelId, ?int $serviceId, ?string $status, int $perPage = 15): LengthAwarePaginator
    {
        return ServiceReview::query()
            ->where('hotel_id', $hotelId)
            ->when($serviceId !== null, fn (Builder $q) => $q->where('service_id', $serviceId))
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->latest()
            ->paginate($perPage);
    }
}
