<?php

namespace Tests\Unit\Payment\Workflow;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Inventory\Repositories\EloquentRoomRepository;
use App\Domain\Inventory\Repositories\EloquentRoomTypeRepository;
use App\Domain\Payment\Gateway\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Repositories\Contracts\PaymentRepositoryInterface;
use App\Domain\Payment\Repositories\Contracts\PaymentTransactionRepositoryInterface;
use App\Domain\Payment\Repositories\EloquentPaymentRepository;
use App\Domain\Payment\Repositories\EloquentPaymentTransactionRepository;
use App\Domain\Payment\Services\PaymentWorkflowService;
use App\Domain\Reservation\Repositories\EloquentGuestRepository;
use App\Domain\Reservation\Repositories\EloquentReservationRepository;
use App\Domain\Reservation\Services\ReservationService;
use Tests\TestCase;

abstract class PaymentWorkflowTestCase extends TestCase
{
    protected function makeReservationService(?AuditLogger $auditLogger = null): ReservationService
    {
        return new ReservationService(
            new EloquentReservationRepository,
            new EloquentRoomTypeRepository,
            new EloquentRoomRepository,
            new EloquentGuestRepository,
            $auditLogger ?? app(AuditLogger::class),
        );
    }

    protected function makeWorkflow(
        ?PaymentGatewayInterface $gateway = null,
        ?AuditLogger $auditLogger = null,
        ?PaymentRepositoryInterface $payments = null,
        ?PaymentTransactionRepositoryInterface $transactions = null,
    ): PaymentWorkflowService {
        $auditLogger ??= app(AuditLogger::class);

        return new PaymentWorkflowService(
            $gateway ?? app(PaymentGatewayInterface::class),
            $payments ?? new EloquentPaymentRepository,
            $transactions ?? new EloquentPaymentTransactionRepository,
            new EloquentReservationRepository,
            $this->makeReservationService($auditLogger),
            $auditLogger,
        );
    }
}
