<?php

namespace App\Domain\Reporting\Services;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Repositories\Contracts\HotelRepositoryInterface;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Services\HotelAccessService;
use App\Domain\Inventory\Repositories\Contracts\RoomRepositoryInterface;
use App\Domain\Loyalty\Models\LoyaltyTransaction;
use App\Domain\Loyalty\Repositories\Contracts\LoyaltyTransactionRepositoryInterface;
use App\Domain\Payment\Repositories\Contracts\PaymentRepositoryInterface;
use App\Domain\Reservation\Repositories\Contracts\ReservationRepositoryInterface;
use App\Domain\Review\Repositories\Contracts\ReviewRepositoryInterface;
use App\Domain\StayServices\Repositories\Contracts\ServiceOrderRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read-only staff reporting — occupancy, revenue, and a side-by-side hotel
 * comparison. Every figure is derived from the same authoritative domains
 * (Room / Reservation / Payment) the rest of the dashboard reads; nothing
 * is invented, cached, or approximated with a business default. A
 * caller-supplied `hotel_id` outside the caller's own access, or one that
 * does not exist, simply yields an empty hotel set (no data leaked, no
 * error) — the same "never distinguish missing from unauthorized"
 * reasoning the rest of the API uses for scoped reads.
 */
class ReportService
{
    public function __construct(
        private readonly HotelRepositoryInterface $hotels,
        private readonly RoomRepositoryInterface $rooms,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly PaymentRepositoryInterface $payments,
        private readonly ServiceOrderRepositoryInterface $serviceOrders,
        private readonly LoyaltyTransactionRepositoryInterface $loyaltyTransactions,
        private readonly ReviewRepositoryInterface $reviews,
        private readonly HotelAccessService $hotelAccess,
    ) {}

    /**
     * @return array{range: array{from: string, to: string}, hotels: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function occupancy(User $user, ?int $hotelId, string $from, string $to): array
    {
        $nights = $this->nights($from, $to);
        $rows = $this->resolveHotels($user, $hotelId)
            ->map(function (Hotel $hotel) use ($from, $to, $nights) {
                $roomCount = $this->rooms->countByHotel($hotel->id);
                $capacity = $roomCount * $nights;
                $booked = $this->bookedRoomNights($hotel->id, $from, $to);

                return [
                    'hotel_id' => $hotel->id,
                    'hotel_name' => $hotel->name,
                    'rooms' => $roomCount,
                    'nights' => $nights,
                    'capacity_room_nights' => $capacity,
                    'booked_room_nights' => $booked,
                    'occupancy_rate' => $this->rate($booked, $capacity),
                ];
            })
            ->values();

        $totalCapacity = (int) $rows->sum('capacity_room_nights');
        $totalBooked = (int) $rows->sum('booked_room_nights');

        return [
            'range' => ['from' => $from, 'to' => $to],
            'hotels' => $rows->all(),
            'totals' => [
                'rooms' => (int) $rows->sum('rooms'),
                'capacity_room_nights' => $totalCapacity,
                'booked_room_nights' => $totalBooked,
                'occupancy_rate' => $this->rate($totalBooked, $totalCapacity),
            ],
        ];
    }

    /**
     * @return array{range: array{from: string, to: string}, hotels: list<array<string, mixed>>, totals: list<array{currency: string, amount: string}>}
     */
    public function revenue(User $user, ?int $hotelId, string $from, string $to): array
    {
        $totalsByCurrency = [];

        $rows = $this->resolveHotels($user, $hotelId)
            ->map(function (Hotel $hotel) use ($from, $to, &$totalsByCurrency) {
                $entries = $this->payments->sumCapturedForHotelByCurrency($hotel->id, $from, $to)
                    ->map(function ($row) use (&$totalsByCurrency) {
                        $currency = (string) $row->currency;
                        $amount = (string) $row->total;
                        $totalsByCurrency[$currency] = bcadd($totalsByCurrency[$currency] ?? '0.00', $amount, 2);

                        return ['currency' => $currency, 'amount' => $amount];
                    })
                    ->values()
                    ->all();

                return [
                    'hotel_id' => $hotel->id,
                    'hotel_name' => $hotel->name,
                    'revenue' => $entries,
                ];
            })
            ->values();

        $totals = collect($totalsByCurrency)
            ->map(fn (string $amount, string $currency) => ['currency' => $currency, 'amount' => $amount])
            ->values()
            ->all();

        return [
            'range' => ['from' => $from, 'to' => $to],
            'hotels' => $rows->all(),
            'totals' => $totals,
        ];
    }

    /**
     * Occupancy and revenue side-by-side, one row per accessible hotel —
     * always every accessible hotel (a single-hotel "comparison" has
     * nothing to compare, so `hotel_id` is not accepted here).
     *
     * @return array{range: array{from: string, to: string}, hotels: list<array<string, mixed>>}
     */
    public function hotelComparison(User $user, string $from, string $to): array
    {
        $occupancy = $this->occupancy($user, null, $from, $to);
        $revenueByHotel = collect($this->revenue($user, null, $from, $to)['hotels'])->keyBy('hotel_id');

        $rows = collect($occupancy['hotels'])->map(function (array $o) use ($revenueByHotel) {
            return [
                'hotel_id' => $o['hotel_id'],
                'hotel_name' => $o['hotel_name'],
                'occupancy_rate' => $o['occupancy_rate'],
                'booked_room_nights' => $o['booked_room_nights'],
                'revenue' => $revenueByHotel->get($o['hotel_id'])['revenue'] ?? [],
            ];
        })->values();

        return [
            'range' => ['from' => $from, 'to' => $to],
            'hotels' => $rows->all(),
        ];
    }

    /**
     * Reservation counts by status, per accessible hotel — bookings whose
     * check_in falls in [$from, $to].
     *
     * @return array{range: array{from: string, to: string}, hotels: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function reservations(User $user, ?int $hotelId, string $from, string $to): array
    {
        $rows = $this->resolveHotels($user, $hotelId)
            ->map(function (Hotel $hotel) use ($from, $to) {
                $byStatus = $this->intMap($this->reservations->countsByStatusForHotel($hotel->id, $from, $to));

                return [
                    'hotel_id' => $hotel->id,
                    'hotel_name' => $hotel->name,
                    'by_status' => $byStatus,
                    'total' => array_sum($byStatus),
                ];
            })
            ->values();

        return [
            'range' => ['from' => $from, 'to' => $to],
            'hotels' => $rows->all(),
            'totals' => $this->mergeByStatus($rows->pluck('by_status')),
        ];
    }

    /**
     * Payment counts by status, per accessible hotel — payments created
     * in [$from, $to]. A payments-operations view (hold/capture/failure
     * volume), distinct from revenue() which sums only collected money.
     *
     * @return array{range: array{from: string, to: string}, hotels: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function payments(User $user, ?int $hotelId, string $from, string $to): array
    {
        $rows = $this->resolveHotels($user, $hotelId)
            ->map(function (Hotel $hotel) use ($from, $to) {
                $byStatus = $this->intMap($this->payments->countsByStatusForHotel($hotel->id, $from, $to));

                return [
                    'hotel_id' => $hotel->id,
                    'hotel_name' => $hotel->name,
                    'by_status' => $byStatus,
                    'total' => array_sum($byStatus),
                ];
            })
            ->values();

        return [
            'range' => ['from' => $from, 'to' => $to],
            'hotels' => $rows->all(),
            'totals' => $this->mergeByStatus($rows->pluck('by_status')),
        ];
    }

    /**
     * Service-order counts by status and revenue (CONFIRMED/FULFILLED
     * only, grouped by currency), per accessible hotel — orders created
     * in [$from, $to].
     *
     * @return array{range: array{from: string, to: string}, hotels: list<array<string, mixed>>, totals: array{by_status: array<string, int>, revenue: list<array{currency: string, amount: string}>}}
     */
    public function services(User $user, ?int $hotelId, string $from, string $to): array
    {
        $totalsByCurrency = [];

        $rows = $this->resolveHotels($user, $hotelId)
            ->map(function (Hotel $hotel) use ($from, $to, &$totalsByCurrency) {
                $stats = $this->serviceOrders->statsForHotel($hotel->id, $from, $to);
                $byStatus = $this->intMap($stats['by_status']);

                foreach ($stats['revenue'] as $entry) {
                    $totalsByCurrency[$entry['currency']] = bcadd($totalsByCurrency[$entry['currency']] ?? '0.00', $entry['amount'], 2);
                }

                return [
                    'hotel_id' => $hotel->id,
                    'hotel_name' => $hotel->name,
                    'by_status' => $byStatus,
                    'total' => array_sum($byStatus),
                    'revenue' => $stats['revenue'],
                ];
            })
            ->values();

        return [
            'range' => ['from' => $from, 'to' => $to],
            'hotels' => $rows->all(),
            'totals' => [
                'by_status' => $this->mergeByStatus($rows->pluck('by_status')),
                'revenue' => collect($totalsByCurrency)
                    ->map(fn (string $amount, string $currency) => ['currency' => $currency, 'amount' => $amount])
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * Loyalty points earned vs. redeemed, per accessible hotel — ledger
     * entries created in [$from, $to], scoped through each entry's
     * reservation (loyalty accounts themselves are group-wide, but every
     * earn/redeem is always tied to one reservation at one hotel).
     *
     * @return array{range: array{from: string, to: string}, hotels: list<array<string, mixed>>, totals: array{points_earned: int, points_redeemed: int, net: int}}
     */
    public function loyalty(User $user, ?int $hotelId, string $from, string $to): array
    {
        $rows = $this->resolveHotels($user, $hotelId)
            ->map(function (Hotel $hotel) use ($from, $to) {
                $byType = $this->loyaltyTransactions->sumsByTypeForHotel($hotel->id, $from, $to);
                $earned = $byType[LoyaltyTransaction::TYPE_EARN] ?? 0;
                // Redeem entries are stored as a negative points delta.
                $redeemed = abs($byType[LoyaltyTransaction::TYPE_REDEEM] ?? 0);

                return [
                    'hotel_id' => $hotel->id,
                    'hotel_name' => $hotel->name,
                    'points_earned' => $earned,
                    'points_redeemed' => $redeemed,
                    'net' => $earned - $redeemed,
                ];
            })
            ->values();

        $earnedTotal = (int) $rows->sum('points_earned');
        $redeemedTotal = (int) $rows->sum('points_redeemed');

        return [
            'range' => ['from' => $from, 'to' => $to],
            'hotels' => $rows->all(),
            'totals' => [
                'points_earned' => $earnedTotal,
                'points_redeemed' => $redeemedTotal,
                'net' => $earnedTotal - $redeemedTotal,
            ],
        ];
    }

    /**
     * Review counts by moderation status and average rating, per
     * accessible hotel — reviews submitted in [$from, $to].
     *
     * @return array{range: array{from: string, to: string}, hotels: list<array<string, mixed>>, totals: array{by_status: array<string, int>, average_rating: float|null, count: int}}
     */
    public function reviews(User $user, ?int $hotelId, string $from, string $to): array
    {
        $totalRatingSum = 0;
        $totalRatedCount = 0;

        $rows = $this->resolveHotels($user, $hotelId)
            ->map(function (Hotel $hotel) use ($from, $to, &$totalRatingSum, &$totalRatedCount) {
                $stats = $this->reviews->statsForHotel($hotel->id, $from, $to);
                $byStatus = $this->intMap($stats['by_status']);
                $totalRatingSum += $stats['rating_sum'];
                $totalRatedCount += $stats['rated_count'];

                return [
                    'hotel_id' => $hotel->id,
                    'hotel_name' => $hotel->name,
                    'by_status' => $byStatus,
                    'total' => array_sum($byStatus),
                    'average_rating' => $stats['rated_count'] > 0
                        ? round($stats['rating_sum'] / $stats['rated_count'], 2)
                        : null,
                ];
            })
            ->values();

        return [
            'range' => ['from' => $from, 'to' => $to],
            'hotels' => $rows->all(),
            'totals' => [
                'by_status' => $this->mergeByStatus($rows->pluck('by_status')),
                'average_rating' => $totalRatedCount > 0 ? round($totalRatingSum / $totalRatedCount, 2) : null,
                'count' => $totalRatedCount,
            ],
        ];
    }

    /**
     * @param  array<string, int|string>  $counts
     * @return array<string, int>
     */
    private function intMap(array $counts): array
    {
        return array_map(fn ($v) => (int) $v, $counts);
    }

    /**
     * @param  Collection<int, array<string, int>>  $perHotel
     * @return array<string, int>
     */
    private function mergeByStatus(Collection $perHotel): array
    {
        $merged = [];

        foreach ($perHotel as $byStatus) {
            foreach ($byStatus as $status => $count) {
                $merged[$status] = ($merged[$status] ?? 0) + $count;
            }
        }

        return $merged;
    }

    /**
     * $hotelId narrows to one hotel (only if the caller can access it —
     * otherwise an empty set, never a 403 that would confirm the id
     * exists); null means every hotel the caller can access.
     *
     * @return Collection<int, Hotel>
     */
    private function resolveHotels(User $user, ?int $hotelId): Collection
    {
        if ($hotelId !== null) {
            $hotel = $this->hotels->find($hotelId);

            if ($hotel === null || ! $this->hotelAccess->canAccessHotel($user, $hotel->id)) {
                return collect();
            }

            return collect([$hotel]);
        }

        return collect($this->hotels->paginateAccessibleBy($user, [], 100)->items());
    }

    /**
     * Whole nights in [$from, $to) — matches the checkout-exclusive
     * overlap predicate used everywhere else (Reservation domain §3D).
     * Never less than 1, so a same-day range still reports a defined
     * (zero-capacity) window rather than dividing by zero.
     */
    private function nights(string $from, string $to): int
    {
        $nights = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to));

        return max($nights, 1);
    }

    /**
     * Sum of each overlapping reservation's nights clamped to [$from, $to)
     * — a stay that starts before or ends after the window only counts the
     * nights that actually fall inside it.
     */
    private function bookedRoomNights(int $hotelId, string $from, string $to): int
    {
        $rangeStart = CarbonImmutable::parse($from);
        $rangeEnd = CarbonImmutable::parse($to);

        return $this->reservations->overlappingForHotel($hotelId, $from, $to)
            ->sum(function ($reservation) use ($rangeStart, $rangeEnd) {
                $checkIn = CarbonImmutable::parse($reservation->check_in);
                $checkOut = CarbonImmutable::parse($reservation->check_out);

                $clampedStart = $checkIn->greaterThan($rangeStart) ? $checkIn : $rangeStart;
                $clampedEnd = $checkOut->lessThan($rangeEnd) ? $checkOut : $rangeEnd;

                return max($clampedStart->diffInDays($clampedEnd, false), 0);
            });
    }

    /**
     * A percentage, two decimals, display-only — never used for a
     * financial or capacity decision elsewhere.
     */
    private function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : 0.0;
    }
}
