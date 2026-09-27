<?php

namespace Tests\Unit\Loyalty;

use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Models\LoyaltyRule;
use App\Domain\Loyalty\Models\LoyaltyTransaction;
use App\Domain\Reservation\Models\Guest;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LoyaltySchemaTest extends TestCase
{
    public function test_tables_exist(): void
    {
        foreach (['loyalty_accounts', 'loyalty_rules', 'loyalty_transactions'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "missing {$table}");
        }
    }

    public function test_one_account_per_guest(): void
    {
        $account = LoyaltyAccount::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        LoyaltyAccount::factory()->create(['guest_id' => $account->guest_id]);
    }

    public function test_one_rule_per_hotel_group(): void
    {
        $rule = LoyaltyRule::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        LoyaltyRule::factory()->create(['hotel_group_id' => $rule->hotel_group_id]);
    }

    public function test_one_earn_and_one_redeem_per_account_and_source(): void
    {
        $account = LoyaltyAccount::factory()->create();
        LoyaltyTransaction::factory()->earn(100)->create(['loyalty_account_id' => $account->id, 'source_id' => 55]);
        // a redeem for the same source is fine (different type)
        LoyaltyTransaction::factory()->redeem(10)->create(['loyalty_account_id' => $account->id, 'source_id' => 55]);

        $this->expectException(UniqueConstraintViolationException::class);
        LoyaltyTransaction::factory()->earn(100)->create(['loyalty_account_id' => $account->id, 'source_id' => 55]);
    }

    public function test_source_less_adjustments_are_not_constrained(): void
    {
        $account = LoyaltyAccount::factory()->create();

        LoyaltyTransaction::factory()->create([
            'loyalty_account_id' => $account->id, 'type' => LoyaltyTransaction::TYPE_ADJUST,
            'points' => 10, 'source_type' => null, 'source_id' => null,
        ]);
        LoyaltyTransaction::factory()->create([
            'loyalty_account_id' => $account->id, 'type' => LoyaltyTransaction::TYPE_ADJUST,
            'points' => -5, 'source_type' => null, 'source_id' => null,
        ]);

        $this->assertSame(2, LoyaltyTransaction::where('type', 'adjust')->count());
    }

    public function test_points_are_signed_integers(): void
    {
        $txn = LoyaltyTransaction::factory()->create(['points' => -12345, 'source_id' => 1]);
        $this->assertSame(-12345, $txn->fresh()->points);

        $account = LoyaltyAccount::factory()->create(['points_balance' => -7]);
        $this->assertSame(-7, $account->fresh()->points_balance);
    }

    public function test_rule_rates_keep_four_decimals_and_stay_null_by_default(): void
    {
        $rule = LoyaltyRule::factory()->create();
        $this->assertNull($rule->fresh()->earn_points_per_currency);
        $this->assertFalse($rule->fresh()->is_active);

        $configured = LoyaltyRule::factory()->create(['earn_points_per_currency' => '1.2345', 'redeem_currency_per_point' => '0.0100']);
        $this->assertSame('1.2345', $configured->fresh()->earn_points_per_currency);
    }

    public function test_ledger_has_no_updated_at(): void
    {
        $this->assertFalse(Schema::hasColumn('loyalty_transactions', 'updated_at'));
        $this->assertTrue(Schema::hasColumn('loyalty_transactions', 'created_at'));
    }

    public function test_deleting_a_guest_with_an_account_is_blocked(): void
    {
        $account = LoyaltyAccount::factory()->create();

        $this->expectException(QueryException::class);
        Guest::query()->whereKey($account->guest_id)->delete();
    }

    public function test_deleting_a_hotel_group_with_a_rule_is_blocked(): void
    {
        $rule = LoyaltyRule::factory()->create();

        $this->expectException(QueryException::class);
        HotelGroup::query()->whereKey($rule->hotel_group_id)->delete();
    }
}
