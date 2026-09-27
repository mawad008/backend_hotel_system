<?php

namespace App\Domain\Review\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Loyalty\Services\LoyaltyService;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Review\Exceptions\ReviewNotAllowedException;
use App\Domain\Review\Models\Review;
use App\Domain\Review\Models\ReviewCategory;
use App\Domain\Review\Repositories\Contracts\ReviewCategoryRepositoryInterface;
use App\Domain\Review\Repositories\Contracts\ReviewRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Post-stay review workflow (mobile/docs/mobile-phase-10-loyalty-reviews.md
 * "Review domain"). Eligibility mirrors the loyalty-earn definition of a
 * "completed/stayed" reservation exactly — both are the same Phase 0 §14
 * concept, so the statuses are not duplicated here.
 *
 * ── Invariants ──
 * - One review per reservation — the `reviews.reservation_id` UNIQUE is
 *   authoritative; a second submit for the same reservation is an idempotent
 *   no-op that returns the existing review, never a duplicate or an error.
 * - The client never sets guest_id, hotel_id, or the moderation state —
 *   all server-derived from the reservation / the acting staff user.
 * - Optional per-category ratings against the hotel's **dynamic**,
 *   dashboard-managed [ReviewCategory]s (never hardcoded): each must be an
 *   active category of the reservation's hotel, rated at most once. They are
 *   stored with a snapshot of the category's labels so the review stays
 *   readable after a rename.
 * - No editing/deleting a submitted review and no arbitrary (non-stay)
 *   reviews — neither is approved (§ Explicitly NOT built in the mobile doc).
 */
class ReviewService
{
    public function __construct(
        private readonly ReviewRepositoryInterface $reviews,
        private readonly ReviewCategoryRepositoryInterface $categories,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function reviewFor(Reservation $reservation): ?Review
    {
        return $this->reviews->findByReservation($reservation->id);
    }

    public function publishedForHotel(int $hotelId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->reviews->paginatePublishedForHotel($hotelId, $perPage);
    }

    public function allForHotel(int $hotelId, ?string $status, int $perPage = 15): LengthAwarePaginator
    {
        return $this->reviews->paginateForHotel($hotelId, $status, $perPage);
    }

    /**
     * Submit a review for a completed reservation. Idempotent: a second
     * submit for the same reservation returns the existing review with
     * `created: false` rather than raising an error or writing a duplicate
     * (the project's API has no 409/conflict convention — the controller
     * signals "already existed" via a 200 instead of 201, not an exception).
     *
     * @param  list<array{category_id: int, rating: int}>  $categoryRatings
     * @return array{review: Review, created: bool}
     *
     * @throws ReviewNotAllowedException if the reservation is not completed,
     *                                   or a rated category is not an active category of its hotel
     */
    public function submitForReservation(Reservation $reservation, int $rating, ?string $text, ?User $actor = null, array $categoryRatings = []): array
    {
        $existing = $this->reviews->findByReservation($reservation->id);

        if ($existing !== null) {
            return ['review' => $existing, 'created' => false];
        }

        if (! in_array($reservation->status, LoyaltyService::COMPLETED_RESERVATION_STATUSES, true)) {
            throw ReviewNotAllowedException::reservationNotCompleted($reservation->status);
        }

        // Only the hotel's *currently active* categories can be rated — an
        // unknown, other-hotel or deactivated id is rejected, never ignored.
        $active = $this->categories->forHotel($reservation->hotel_id, activeOnly: true)->keyBy('id');
        foreach ($categoryRatings as $entry) {
            if (! $active->has((int) $entry['category_id'])) {
                throw ReviewNotAllowedException::invalidCategory((int) $entry['category_id']);
            }
        }

        try {
            $review = DB::transaction(function () use ($reservation, $rating, $text, $categoryRatings, $active): Review {
                $review = $this->reviews->create([
                    'reservation_id' => $reservation->id,
                    'guest_id' => $reservation->guest_id,
                    'hotel_id' => $reservation->hotel_id,
                    'rating' => $rating,
                    'text' => $text,
                    'status' => Review::STATUS_PENDING,
                ]);

                foreach ($categoryRatings as $entry) {
                    /** @var ReviewCategory $category */
                    $category = $active->get((int) $entry['category_id']);
                    $review->categoryRatings()->create([
                        'review_category_id' => $category->id,
                        'rating' => (int) $entry['rating'],
                        // Snapshot of the labels the guest actually rated.
                        'category_name' => $category->name,
                        'category_name_ar' => $category->name_ar,
                        'category_name_en' => $category->name_en,
                    ]);
                }

                return $review;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent submit for the same reservation won the race —
            // the UNIQUE constraint is authoritative.
            return [
                'review' => $this->reviews->findByReservation($reservation->id)
                    ?? throw new ReviewNotAllowedException('review_write_race'),
                'created' => false,
            ];
        }

        $this->auditLogger->record(
            $actor, 'review.submitted', $review,
            after: [
                'rating' => $review->rating,
                'status' => $review->status,
                'category_ratings' => count($categoryRatings),
            ],
            hotelId: $reservation->hotel_id,
        );

        return ['review' => $review, 'created' => true];
    }

    /**
     * Staff moderation decision. `$decision` is validated by the caller's
     * FormRequest to `published`|`rejected` before this is ever called.
     */
    public function moderate(Review $review, string $decision, ?User $actor = null): Review
    {
        $updated = $this->reviews->update($review, [
            'status' => $decision,
            'moderated_by_user_id' => $actor?->id,
            'moderated_at' => now(),
        ]);

        $this->auditLogger->record(
            $actor, 'review.moderated', $updated,
            after: ['status' => $updated->status],
            hotelId: $updated->hotel_id,
        );

        return $updated;
    }
}
