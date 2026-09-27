<?php

namespace App\Domain\Payment\Gateway;

/**
 * Phase 5B — the provider operations exposed by PaymentGatewayInterface
 * (Phase 5B instructions §4). The string values deliberately match
 * PaymentTransaction::TYPES so a later phase persisting a gateway result
 * does not need a translation layer.
 *
 * `refund` is intentionally absent: refund business behaviour is deferred
 * and the approved 5B operation set does not include it (Phase 5B §4/§10).
 */
enum GatewayOperation: string
{
    case Hold = 'hold';

    case CancelHold = 'cancel_hold';

    case Capture = 'capture';

    case Settlement = 'settlement';

    case Verify = 'verify';
}
