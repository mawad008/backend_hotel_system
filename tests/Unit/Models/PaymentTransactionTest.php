<?php

namespace Tests\Unit\Models;

use App\Domain\IdentityAccess\Models\User;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentTransactionTest extends TestCase
{
    public function test_factory_creates_a_valid_pending_hold_transaction(): void
    {
        $transaction = PaymentTransaction::factory()->create();

        $this->assertDatabaseHas('payment_transactions', ['id' => $transaction->id]);
        $this->assertSame(PaymentTransaction::TYPE_HOLD, $transaction->type);
        $this->assertSame(PaymentTransaction::STATUS_PENDING, $transaction->status);
    }

    public function test_belongs_to_a_payment(): void
    {
        $payment = Payment::factory()->create();
        $transaction = PaymentTransaction::factory()->create(['payment_id' => $payment->id]);

        $this->assertTrue($transaction->payment->is($payment));
    }

    public function test_payment_id_is_required(): void
    {
        $this->expectException(QueryException::class);

        PaymentTransaction::factory()->create(['payment_id' => null]);
    }

    public function test_deleting_a_payment_with_transactions_is_blocked(): void
    {
        $transaction = PaymentTransaction::factory()->create();

        $this->expectException(QueryException::class);

        $transaction->payment->delete();
    }

    public function test_idempotency_key_is_unique(): void
    {
        $transaction = PaymentTransaction::factory()->create(['idempotency_key' => 'key-abc']);

        $this->assertSame('key-abc', $transaction->fresh()->idempotency_key);

        $this->expectException(QueryException::class);

        PaymentTransaction::factory()->create(['idempotency_key' => 'key-abc']);
    }

    public function test_all_six_approved_types_are_accepted(): void
    {
        $this->assertCount(6, PaymentTransaction::TYPES);

        foreach (PaymentTransaction::TYPES as $type) {
            $transaction = PaymentTransaction::factory()->create(['type' => $type]);
            $this->assertSame($type, $transaction->fresh()->type);
        }
    }

    public function test_column_rejects_an_unapproved_type(): void
    {
        $transaction = PaymentTransaction::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('payment_transactions')->where('id', $transaction->id)->update(['type' => 'chargeback']);
    }

    public function test_all_five_approved_statuses_are_accepted(): void
    {
        $this->assertCount(5, PaymentTransaction::STATUSES);

        foreach (PaymentTransaction::STATUSES as $status) {
            $transaction = PaymentTransaction::factory()->create(['status' => $status]);
            $this->assertSame($status, $transaction->fresh()->status);
        }
    }

    public function test_column_rejects_an_unapproved_status(): void
    {
        $transaction = PaymentTransaction::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('payment_transactions')->where('id', $transaction->id)->update(['status' => 'refunded']);
    }

    public function test_requested_by_user_id_is_nullable_and_nulls_on_user_delete(): void
    {
        $staff = User::factory()->hotelManager()->create();
        $transaction = PaymentTransaction::factory()->create(['requested_by_user_id' => $staff->id]);

        $this->assertTrue($transaction->requestedBy->is($staff));

        $staff->delete();

        $this->assertNull($transaction->fresh()->requested_by_user_id);
    }

    public function test_requested_by_user_id_defaults_to_null_for_a_system_operation(): void
    {
        $transaction = PaymentTransaction::factory()->create();

        $this->assertNull($transaction->fresh()->requested_by_user_id);
    }

    public function test_provider_reference_is_nullable_and_unique_per_provider_when_present(): void
    {
        $first = PaymentTransaction::factory()->create(['provider' => 'dummy', 'provider_reference' => 'ref-1']);
        $this->assertSame('ref-1', $first->fresh()->provider_reference);

        // Two un-referenced rows for the same provider are allowed (NULL).
        PaymentTransaction::factory()->create(['provider' => 'dummy', 'provider_reference' => null]);
        PaymentTransaction::factory()->create(['provider' => 'dummy', 'provider_reference' => null]);
        $this->assertSame(2, PaymentTransaction::whereNull('provider_reference')->count());

        // A duplicate (provider, provider_reference) is rejected.
        $this->expectException(QueryException::class);
        PaymentTransaction::factory()->create(['provider' => 'dummy', 'provider_reference' => 'ref-1']);
    }

    public function test_metadata_casts_to_and_from_an_array(): void
    {
        $transaction = PaymentTransaction::factory()->create(['metadata' => ['outcome' => 'succeeded', 'reference' => 'r1']]);

        $this->assertSame(['outcome' => 'succeeded', 'reference' => 'r1'], $transaction->fresh()->metadata);
    }

    public function test_metadata_is_nullable(): void
    {
        $transaction = PaymentTransaction::factory()->create();

        $this->assertNull($transaction->fresh()->metadata);
    }
}
