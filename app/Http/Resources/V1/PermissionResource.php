<?php

namespace App\Http\Resources\V1;

use App\Domain\IdentityAccess\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Permission
 */
class PermissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $group = $this->group();

        return [
            'id' => $this->id,
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
            'slug' => $this->slug,
            'description_en' => $this->description_en,
            'description_ar' => $this->description_ar,
            'group' => $group,
            'group_label_en' => __('permission_groups.'.$group, [], 'en'),
            'group_label_ar' => __('permission_groups.'.$group, [], 'ar'),
        ];
    }
}
