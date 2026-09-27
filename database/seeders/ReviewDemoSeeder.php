<?php

namespace Database\Seeders;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Review\Models\Review;
use App\Domain\Review\Models\ReviewCategory;
use App\Domain\Review\Models\ReviewCategoryRating;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Local / demo data so the guest Hotel Detail and the dashboard's review
 * screens have something real to show. For every active hotel it fills in
 * **only what is missing** — never overwrites existing data:
 *
 * - facilities: attaches a few from the existing catalog when the hotel has
 *   none;
 * - review categories: a varied set (4-6) when the hotel has none — hotels
 *   get different sets, which is the point of dynamic categories;
 * - guest reviews: past, completed stays by demo guests with an overall
 *   rating, per-category ratings (with name snapshots) and a short comment,
 *   until the hotel has at least 8 published reviews (plus a couple pending).
 *
 * Averages are then computed by the backend from these rows like any real
 * data. Idempotent (safe to re-run) and never runs in production.
 */
class ReviewDemoSeeder extends Seeder
{
    private const MIN_PUBLISHED = 8;

    /** @var list<array{0: string, 1: string, 2: string}> [ar, en, icon] */
    private const CATEGORY_POOL = [
        ['النظافة', 'Cleanliness', 'sparkles'],
        ['الراحة', 'Comfort', 'bed'],
        ['الموقع', 'Location', 'map-pin'],
        ['الخدمة', 'Service', 'headset'],
        ['جودة الغرف', 'Room quality', 'bed-double'],
        ['الإفطار', 'Breakfast', 'coffee'],
        ['تعامل الموظفين', 'Staff', 'users'],
        ['القيمة مقابل السعر', 'Value for money', 'wallet'],
        ['الهدوء', 'Quietness', 'moon'],
    ];

    private const GUEST_NAMES = [
        'أحمد السالم', 'سارة العتيبي', 'محمد القحطاني', 'نورة الشمري', 'خالد الدوسري',
        'ريم الزهراني', 'عبدالله الحربي', 'لمى المطيري', 'فهد الغامدي', 'هند العنزي',
        'يوسف المالكي', 'منى الشهري', 'عمر البقمي', 'دانة السبيعي', 'ماجد الرشيدي',
    ];

    private const COMMENTS = [
        'إقامة رائعة والغرفة نظيفة جدًا، والموظفون متعاونون.',
        'الموقع ممتاز وقريب من كل شيء. سأعود مرة أخرى.',
        'تجربة جيدة بشكل عام، الإفطار متنوع ولذيذ.',
        'الغرفة واسعة ومريحة، وتسجيل الدخول كان سريعًا.',
        'خدمة ممتازة واهتمام بالتفاصيل.',
        'المكان هادئ ومناسب للعائلات.',
        'السعر مناسب مقارنة بمستوى الخدمة.',
        null,
        null,
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        foreach (Hotel::query()->where('is_active', true)->with('roomTypes')->get() as $hotel) {
            DB::transaction(function () use ($hotel): void {
                $this->seedFacilities($hotel);
                $categories = $this->seedCategories($hotel);
                $this->seedReviews($hotel, $categories);
            });
        }
    }

    private function seedFacilities(Hotel $hotel): void
    {
        if ($hotel->facilities()->exists()) {
            return;
        }

        $ids = DB::table('facilities')->where('is_active', true)->inRandomOrder()->limit(6)->pluck('id');
        if ($ids->isNotEmpty()) {
            $hotel->facilities()->syncWithoutDetaching($ids->all());
        }
    }

    /** @return list<ReviewCategory> the hotel's active categories */
    private function seedCategories(Hotel $hotel): array
    {
        if (! ReviewCategory::query()->where('hotel_id', $hotel->id)->exists()) {
            $picked = collect(self::CATEGORY_POOL)->shuffle()->take(random_int(4, 6))->values();
            foreach ($picked as $order => [$ar, $en, $icon]) {
                ReviewCategory::query()->create([
                    'hotel_id' => $hotel->id,
                    'name' => $ar,
                    'name_ar' => $ar,
                    'name_en' => $en,
                    'icon' => $icon,
                    'sort_order' => $order,
                    'is_active' => true,
                ]);
            }
        }

        return ReviewCategory::query()
            ->where('hotel_id', $hotel->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    /** @param  list<ReviewCategory>  $categories */
    private function seedReviews(Hotel $hotel, array $categories): void
    {
        $roomType = $hotel->roomTypes->first();
        if ($roomType === null) {
            return;
        }

        $published = Review::query()->where('hotel_id', $hotel->id)->where('status', Review::STATUS_PUBLISHED)->count();
        $missing = max(0, self::MIN_PUBLISHED - $published);
        if ($missing === 0) {
            return;
        }

        // The missing published reviews plus two awaiting moderation.
        $total = $missing + 2;
        for ($i = 0; $i < $total; $i++) {
            $status = $i < $missing ? Review::STATUS_PUBLISHED : Review::STATUS_PENDING;
            $guest = $this->demoGuest($hotel, $i);

            $checkOut = Carbon::today()->subDays(random_int(3, 120));
            $checkIn = (clone $checkOut)->subDays(random_int(1, 5));
            $reservation = Reservation::query()->create([
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'room_id' => null,
                'guest_id' => $guest->id,
                'check_in' => $checkIn->toDateString(),
                'check_out' => $checkOut->toDateString(),
                'adults' => random_int(1, 2),
                'children' => random_int(0, 1),
                'status' => Reservation::STATUS_INVOICED,
                'price_snapshot' => number_format((float) $roomType->base_price * $checkIn->diffInDays($checkOut), 2, '.', ''),
                'created_by_staff_id' => null,
            ]);

            // Mostly happy guests, some mixed — a realistic spread.
            $overall = $this->weightedRating();
            $createdAt = (clone $checkOut)->addDays(random_int(0, 2))->setTime(random_int(9, 22), random_int(0, 59));
            $review = new Review([
                'reservation_id' => $reservation->id,
                'guest_id' => $guest->id,
                'hotel_id' => $hotel->id,
                'rating' => $overall,
                'text' => self::COMMENTS[array_rand(self::COMMENTS)],
                'status' => $status,
                'moderated_at' => $status === Review::STATUS_PUBLISHED ? $createdAt : null,
            ]);
            $review->created_at = $createdAt;
            $review->updated_at = $createdAt;
            $review->save();

            foreach ($categories as $category) {
                // Guests rate most, not always all, categories.
                if (random_int(1, 10) > 8) {
                    continue;
                }
                ReviewCategoryRating::query()->create([
                    'review_id' => $review->id,
                    'review_category_id' => $category->id,
                    'rating' => max(1, min(5, $overall + random_int(-1, 1))),
                    'category_name' => $category->name,
                    'category_name_ar' => $category->name_ar,
                    'category_name_en' => $category->name_en,
                ]);
            }
        }
    }

    private function demoGuest(Hotel $hotel, int $index): Guest
    {
        $n = ($hotel->id * 100) + $index;

        return Guest::query()->firstOrCreate(
            ['email' => "demo-guest-{$n}@example.test"],
            [
                'name' => self::GUEST_NAMES[$n % count(self::GUEST_NAMES)],
                'phone' => '+9665'.str_pad((string) (70000000 + $n), 8, '0', STR_PAD_LEFT),
                'phone_verified_at' => now(),
                'profile_completed_at' => now(),
            ],
        );
    }

    /** 5 most often, then 4, some 3, rarely lower. */
    private function weightedRating(): int
    {
        $roll = random_int(1, 100);

        return match (true) {
            $roll <= 45 => 5,
            $roll <= 80 => 4,
            $roll <= 94 => 3,
            $roll <= 98 => 2,
            default => 1,
        };
    }
}
