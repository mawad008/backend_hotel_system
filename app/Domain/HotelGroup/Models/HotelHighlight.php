<?php

namespace App\Domain\HotelGroup\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "why choose this hotel" feature card on the guest Hotel Detail —
 * admin-managed per hotel from the dashboard hotel form.
 */
class HotelHighlight extends Model
{
    protected $fillable = [
        'hotel_id',
        'icon',
        'title_i18n',
        'subtitle_i18n',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'title_i18n' => 'array',
            'subtitle_i18n' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
