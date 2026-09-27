<?php

namespace App\Domain\Loyalty\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\Loyalty\Models\LoyaltyRule;
use App\Domain\Loyalty\Repositories\Contracts\LoyaltyRuleRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10 — configuration of a Hotel Group's loyalty economics
 * (Phase 0 §7: "Configure loyalty rules" is Group-Owner-only; §13: no value
 * is ever seeded or defaulted).
 */
class LoyaltyRuleService
{
    public function __construct(
        private readonly LoyaltyRuleRepositoryInterface $rules,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * The group's loyalty rule, created inactive/unconfigured on first
     * access so the config endpoint always has a row to show.
     */
    public function ruleFor(HotelGroup $group): LoyaltyRule
    {
        $rule = $this->rules->findByHotelGroup($group->id);

        if ($rule !== null) {
            return $rule;
        }

        try {
            return $this->rules->create([
                'hotel_group_id' => $group->id,
                'is_active' => false,
                'earn_points_per_currency' => null,
                'redeem_currency_per_point' => null,
                'eligible_source_types' => [LoyaltyRule::SOURCE_RESERVATION],
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->rules->findByHotelGroup($group->id);
        }
    }

    /**
     * @param  array<string, mixed>  $data  validated: is_active?, earn_points_per_currency?, redeem_currency_per_point?
     */
    public function update(HotelGroup $group, array $data, ?User $actor): LoyaltyRule
    {
        return DB::transaction(function () use ($group, $data, $actor) {
            $rule = $this->ruleFor($group);
            $before = $rule->only(['is_active', 'earn_points_per_currency', 'redeem_currency_per_point', 'max_redeem_points']);

            // hotel_group_id / eligible_source_types are never client-set
            // here (the route group wins; the source set is structural).
            unset($data['hotel_group_id'], $data['eligible_source_types']);

            $rule = $this->rules->update($rule, $data);

            $this->auditLogger->record(
                $actor,
                'loyalty_rule.updated',
                $rule,
                before: $before,
                after: $rule->only(['is_active', 'earn_points_per_currency', 'redeem_currency_per_point', 'max_redeem_points']),
            );

            return $rule;
        });
    }
}
