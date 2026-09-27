<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\Loyalty\Models\LoyaltyRule;
use App\Domain\Loyalty\Services\LoyaltyRuleService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Loyalty\UpdateLoyaltyRuleRequest;
use App\Http\Resources\V1\LoyaltyRuleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 10 — configuration of a Hotel Group's loyalty economics
 * (Phase 0 §7: Group-Owner-only). Nested under the existing
 * /api/v1/hotel-groups/{hotel_group} surface; the group is route-model
 * bound and the policy re-checks the permission.
 */
class LoyaltyRuleController extends Controller
{
    public function __construct(private readonly LoyaltyRuleService $rules) {}

    /**
     * GET /api/v1/hotel-groups/{hotel_group}/loyalty-rule
     */
    public function show(Request $request, HotelGroup $hotel_group): JsonResponse
    {
        $this->authorize('view', [LoyaltyRule::class, $hotel_group]);

        return $this->success(
            new LoyaltyRuleResource($this->rules->ruleFor($hotel_group)),
            __('api.loyalty.rule'),
        );
    }

    /**
     * PUT/PATCH /api/v1/hotel-groups/{hotel_group}/loyalty-rule
     */
    public function update(UpdateLoyaltyRuleRequest $request, HotelGroup $hotel_group): JsonResponse
    {
        $this->authorize('manage', [LoyaltyRule::class, $hotel_group]);

        $rule = $this->rules->update($hotel_group, $request->validated(), $request->user());

        return $this->success(new LoyaltyRuleResource($rule), __('api.updated'));
    }
}
