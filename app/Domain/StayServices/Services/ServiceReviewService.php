<?php

namespace App\Domain\StayServices\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\StayServices\Exceptions\ServiceReviewNotAllowedException;
use App\Domain\StayServices\Models\ServiceOrder;
use App\Domain\StayServices\Models\ServiceReview;
use App\Domain\StayServices\Repositories\Contracts\ServiceReviewRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Rate-a-service workflow — the service-level analogue of ReviewService,
 * kept as a fully separate domain concept (see ServiceReview doc comment):
 * a service review never touches a hotel's own `reviews`/rating, and vice
 * versa. Only a `fulfilled` service order may be reviewed — the delivered
 * state, analogous to how Review requires a completed/stayed reservation.
 *
 * ── Invariants ──
 * - One review per service order — the `service_reviews.service_order_id`
 *   UNIQUE is authoritative; a second submit is an idempotent no-op that
 *   returns the existing review, never a duplicate or an error.
 * - The client never sets guest_id, hotel_id, service_id, or the moderation
 *   state — all server-derived from the service order.
 */
class ServiceReviewService
{
    public function __construct(
        private readonly ServiceReviewRepositoryInterface $reviews,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function reviewFor(ServiceOrder $order): ?ServiceReview
    {
        return $this->reviews->findByServiceOrder($order->id);
    }

    public function forHotel(int $hotelId, ?int $serviceId, ?string $status, int $perPage = 15): LengthAwarePaginator
    {
        return $this->reviews->paginateForHotel($hotelId, $serviceId, $status, $perPage);
    }

    /**
     * Submit a review for a fulfilled service order. Idempotent: a second
     * submit for the same order returns the existing review with
     * `created: false` rather than raising an error or writing a duplicate.
     *
     * @return array{review: ServiceReview, created: bool}
     *
     * @throws ServiceReviewNotAllowedException if the order is not fulfilled
     */
    public function submitForServiceOrder(ServiceOrder $order, int $rating, ?string $text, ?User $actor = null): array
    {
        $existing = $this->reviews->findByServiceOrder($order->id);

        if ($existing !== null) {
            return ['review' => $existing, 'created' => false];
        }

        if ($order->status !== ServiceOrder::STATUS_FULFILLED) {
            throw ServiceReviewNotAllowedException::orderNotFulfilled($order->status);
        }

        try {
            $review = $this->reviews->create([
                'service_order_id' => $order->id,
                'guest_id' => $order->reservation->guest_id,
                'hotel_id' => $order->hotel_id,
                'service_id' => $order->service_id,
                'rating' => $rating,
                'text' => $text,
                'status' => ServiceReview::STATUS_PENDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent submit for the same order won the race — the
            // UNIQUE constraint is authoritative.
            return [
                'review' => $this->reviews->findByServiceOrder($order->id)
                    ?? throw new ServiceReviewNotAllowedException('service_review_write_race'),
                'created' => false,
            ];
        }

        $this->auditLogger->record(
            $actor, 'service_review.submitted', $review,
            after: ['rating' => $review->rating, 'status' => $review->status],
            hotelId: $order->hotel_id,
        );

        return ['review' => $review, 'created' => true];
    }

    /**
     * Staff moderation decision. `$decision` is validated by the caller's
     * FormRequest to `published`|`rejected` before this is ever called.
     */
    public function moderate(ServiceReview $review, string $decision, ?User $actor = null): ServiceReview
    {
        $updated = $this->reviews->update($review, [
            'status' => $decision,
            'moderated_by_user_id' => $actor?->id,
            'moderated_at' => now(),
        ]);

        $this->auditLogger->record(
            $actor, 'service_review.moderated', $updated,
            after: ['status' => $updated->status],
            hotelId: $updated->hotel_id,
        );

        return $updated;
    }
}
