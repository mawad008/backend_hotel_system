<?php

namespace Tests\Unit\Payment\Workflow\Fakes;

use App\Domain\Payment\Gateway\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Gateway\Data\GatewayHoldRequest;
use App\Domain\Payment\Gateway\Data\GatewayOperationRequest;
use App\Domain\Payment\Gateway\Data\GatewayResult;
use App\Domain\Payment\Gateway\Data\NormalizedWebhook;
use App\Domain\Payment\Gateway\GatewayOperation;
use App\Domain\Payment\Gateway\GatewayResultStatus;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A test double for PaymentGatewayInterface. It records how it was called
 * (count, transaction nesting depth at call time, the request) and returns
 * a caller-chosen deterministic result — proving PaymentWorkflowService
 * never instantiates DummyPaymentGateway and calls the provider outside any
 * workflow-opened transaction.
 */
final class RecordingPaymentGateway implements PaymentGatewayInterface
{
    public int $initiateHoldCalls = 0;

    public ?int $transactionLevelAtCall = null;

    public ?GatewayHoldRequest $lastHoldRequest = null;

    /** @var Closure(): void|null */
    public ?Closure $beforeReturn = null;

    public function __construct(
        public GatewayResultStatus $status = GatewayResultStatus::Succeeded,
        public ?Closure $onInitiateHold = null,
    ) {}

    public function initiateHold(GatewayHoldRequest $request): GatewayResult
    {
        $this->initiateHoldCalls++;
        $this->transactionLevelAtCall = DB::transactionLevel();
        $this->lastHoldRequest = $request;

        if ($this->onInitiateHold !== null) {
            ($this->onInitiateHold)($request);
        }

        if ($this->beforeReturn !== null) {
            ($this->beforeReturn)();
        }

        return new GatewayResult(
            operation: GatewayOperation::Hold,
            status: $this->status,
            providerReference: 'dummy_hold_fake_'.$request->intentReference,
            providerCode: 'dummy_hold_'.$this->status->value,
            message: 'Fake provider hold '.$this->status->value.'.',
            context: [],
        );
    }

    public function cancelHold(GatewayOperationRequest $request): GatewayResult
    {
        throw new LogicException('cancelHold is out of Phase 5C scope');
    }

    public function capture(GatewayOperationRequest $request): GatewayResult
    {
        throw new LogicException('capture is out of Phase 5C scope');
    }

    public function settle(GatewayOperationRequest $request): GatewayResult
    {
        throw new LogicException('settle is out of Phase 5C scope');
    }

    public function verify(GatewayOperationRequest $request): GatewayResult
    {
        throw new LogicException('verify is out of Phase 5C scope');
    }

    public function parseWebhook(string $rawBody): NormalizedWebhook
    {
        throw new LogicException('parseWebhook is out of Phase 5C scope');
    }

    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        throw new LogicException('verifySignature is out of Phase 5C scope');
    }
}
