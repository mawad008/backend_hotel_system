<?php

namespace Tests\Unit\Loyalty;

use App\Domain\Loyalty\Services\LoyaltyRuleService;
use App\Domain\Loyalty\Services\LoyaltyService;
use App\Http\Controllers\Api\V1\LoyaltyController;
use App\Http\Controllers\Api\V1\LoyaltyRuleController;
use App\Http\Requests\Api\V1\Loyalty\RedeemLoyaltyRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class LoyaltyArchitectureTest extends TestCase
{
    private function source(string $class): string
    {
        $code = '';
        foreach (token_get_all(file_get_contents((new ReflectionClass($class))->getFileName())) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    public function test_controllers_are_thin(): void
    {
        foreach ([LoyaltyController::class, LoyaltyRuleController::class] as $class) {
            $code = $this->source($class);
            $this->assertStringNotContainsString('DB::', $code, "{$class} uses DB::");
            $this->assertStringNotContainsString('::query(', $code, "{$class} uses ::query(");
            $this->assertStringNotContainsString('LoyaltyTransaction::', $code, "{$class} touches a model");
        }
    }

    public function test_services_never_touch_the_query_builder_or_models_directly(): void
    {
        foreach ([LoyaltyService::class, LoyaltyRuleService::class] as $class) {
            $code = $this->source($class);
            foreach ([
                'DB::table(', 'DB::select(', 'DB::statement(',
                'LoyaltyAccount::query(', 'LoyaltyAccount::create(',
                'LoyaltyTransaction::query(', 'LoyaltyTransaction::create(',
                'LoyaltyRule::query(', 'LoyaltyRule::create(',
                'Reservation::query(',
            ] as $needle) {
                $this->assertStringNotContainsString($needle, $code, "{$class} must not use {$needle}");
            }
        }
    }

    public function test_ledger_is_authoritative_and_the_balance_moves_only_with_a_ledger_row(): void
    {
        $code = $this->source(LoyaltyService::class);
        // Balance updates only ever happen through the single private helper
        // that also writes the ledger row.
        $this->assertStringContainsString('appendLedgerEntry(', $code);
        $this->assertStringContainsString("'points_balance' => \$account->points_balance + \$data['points']", $code);
        // recompute derives from the ledger, never from a magic number.
        $this->assertStringContainsString('sumPointsForAccount(', $code);
    }

    public function test_points_math_is_integer_or_bcmath_never_float(): void
    {
        $code = $this->source(LoyaltyService::class);
        $this->assertStringNotContainsString('(float)', $code);
        $this->assertStringContainsString('bcmul(', $code);
    }

    public function test_expire_type_is_never_written(): void
    {
        foreach ([LoyaltyService::class, LoyaltyRuleService::class] as $class) {
            $this->assertStringNotContainsString('TYPE_EXPIRE', $this->source($class));
            $this->assertStringNotContainsString("'expire'", $this->source($class));
        }
    }

    public function test_redeem_request_never_reads_an_amount_or_balance(): void
    {
        $code = $this->source(RedeemLoyaltyRequest::class);
        $this->assertStringNotContainsString("'amount'", $code);
        $this->assertStringNotContainsString("'points_balance'", $code);
        $this->assertStringContainsString("'points'", $code);
    }
}
