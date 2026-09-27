<?php

namespace App\Domain\Checkout\Models;

use Database\Factories\InvoiceItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 9 — a frozen line of an invoice, snapshotted from the authoritative
 * folio at checkout time. Phase 9 has one folio source: Phase 8
 * `folio_charges` (posted only).
 */
class InvoiceItem extends Model
{
    use HasFactory;

    public const SOURCE_FOLIO_CHARGE = 'folio_charge';

    protected $fillable = [
        'invoice_id',
        'source_type',
        'source_id',
        'description',
        'quantity',
        'unit_amount',
        'total_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    protected static function newFactory(): InvoiceItemFactory
    {
        return InvoiceItemFactory::new();
    }
}
