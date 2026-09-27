<?php

namespace Tests\Unit\Repositories;

use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Payment\Repositories\Contracts\PaymentTransactionRepositoryInterface;
use App\Domain\Payment\Repositories\EloquentPaymentTransactionRepository;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentTransactionRepositoryTest extends TestCase
{
    private EloquentPaymentTransactionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EloquentPaymentTransactionRepository;
    }

    public function test_it_is_bound_to_the_contract(): void
    {
        $this->assertInstanceOf(
            EloquentPaymentTransactionRepository::class,
            app(PaymentTransactionRepositoryInterface::class),
        );
    }

    public function test_find_returns_a_transaction_by_id(): void
    {
        $transaction = PaymentTransaction::factory()->create();

        $this->assertTrue($this->repository->find($transaction->id)->is($transaction));
        $this->assertNull($this->repository->find(999999));
    }

    public function test_find_by_idempotency_key(): void
    {
        $transaction = PaymentTransaction::factory()->create(['idempotency_key' => 'idem-123']);

        $this->assertTrue($this->repository->findByIdempotencyKey('idem-123')->is($transaction));
        $this->assertNull($this->repository->findByIdempotencyKey('never-used'));
    }

    public function test_create_persists_a_transaction(): void
    {
        $payment = Payment::factory()->create();

        $transaction = $this->repository->create([
            'payment_id' => $payment->id,
            'type' => PaymentTransaction::TYPE_HOLD,
            'status' => PaymentTransaction::STATUS_PENDING,
            'idempotency_key' => 'idem-xyz',
            'provider' => 'dummy',
        ]);

        $this->assertDatabaseHas('payment_transactions', ['id' => $transaction->id, 'idempotency_key' => 'idem-xyz']);
    }

    public function test_find_by_provider_reference(): void
    {
        $transaction = PaymentTransaction::factory()->create([
            'provider' => 'dummy',
            'provider_reference' => 'dummy_hold_ref_abc',
        ]);

        $this->assertTrue($this->repository->findByProviderReference('dummy', 'dummy_hold_ref_abc')->is($transaction));
        $this->assertNull($this->repository->findByProviderReference('dummy', 'dummy_hold_ref_missing'));
        $this->assertNull($this->repository->findByProviderReference('other', 'dummy_hold_ref_abc'));
    }

    public function test_find_for_update_returns_the_transaction_within_a_transaction(): void
    {
        $transaction = PaymentTransaction::factory()->create();

        DB::transaction(function () use ($transaction) {
            $this->assertTrue($this->repository->findForUpdate($transaction->id)->is($transaction));
            $this->assertNull($this->repository->findForUpdate(999999));
        });
    }

    public function test_update_persists_and_returns_the_refreshed_model(): void
    {
        $transaction = PaymentTransaction::factory()->create(['status' => PaymentTransaction::STATUS_PENDING]);

        $updated = $this->repository->update($transaction, [
            'status' => PaymentTransaction::STATUS_SUCCEEDED,
            'provider_reference' => 'dummy_hold_abc',
        ]);

        $this->assertSame(PaymentTransaction::STATUS_SUCCEEDED, $updated->status);
        $this->assertSame('dummy_hold_abc', $updated->provider_reference);
        $this->assertDatabaseHas('payment_transactions', [
            'id' => $transaction->id,
            'status' => PaymentTransaction::STATUS_SUCCEEDED,
        ]);
    }
}
