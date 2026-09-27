<?php

namespace Tests\Unit\Loyalty;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Loyalty\Models\LoyaltyRule;
use App\Domain\Loyalty\Services\LoyaltyRuleService;
use App\Domain\Loyalty\Services\LoyaltyService;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

abstract class LoyaltyTestCase extends TestCase
{
    protected function loyalty(): LoyaltyService
    {
        return app(LoyaltyService::class);
    }

    protected function ruleService(): LoyaltyRuleService
    {
        return app(LoyaltyRuleService::class);
    }

    /**
     * A reservation in $status, belonging to a hotel in a group that has an
     * active, fully-configured loyalty rule (unless $activeRule is false).
     */
    protected function reservationForLoyalty(
        string $status = Reservation::STATUS_INVOICED,
        string $priceSnapshot = '200.00',
        bool $activeRule = true,
        string $earnRate = '1.0000',
        string $redeemValue = '0.0100',
        ?HotelGroup $group = null,
    ): Reservation {
        $group ??= HotelGroup::factory()->create();
        $hotel = Hotel::factory()->create(['hotel_group_id' => $group->id]);
        $roomType = RoomType::factory()->create(['hotel_id' => $hotel->id]);

        if ($activeRule) {
            LoyaltyRule::factory()->active($earnRate, $redeemValue)->create(['hotel_group_id' => $group->id]);
        }

        return Reservation::factory()->create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => $status,
            'price_snapshot' => $priceSnapshot,
        ]);
    }
}
