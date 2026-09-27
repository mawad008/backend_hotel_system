<?php

namespace Tests\Unit\Notification;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Notification\Listeners\SendReservationLifecycleNotifications;
use App\Domain\Notification\Provider\Contracts\NotificationProviderInterface;
use App\Domain\Notification\Repositories\Contracts\NotificationRepositoryInterface;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Reservation\Services\ReservationService;
use App\Http\Controllers\Api\V1\NotificationController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

class NotificationArchitectureTest extends TestCase
{
    private function source(string $class): string
    {
        $code = '';
        foreach (token_get_all(file_get_contents((new ReflectionClass($class))->getFileName())) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    public function test_controller_is_thin(): void
    {
        $code = $this->source(NotificationController::class);

        $this->assertStringNotContainsString('DB::', $code);
        $this->assertStringNotContainsString('::query(', $code);
        $this->assertStringNotContainsString('NotificationDeliveryStateMachine', $code);
        $this->assertStringNotContainsString('NotificationProviderInterface', $code);
        $this->assertStringNotContainsString('Notification::create(', $code);
    }

    public function test_service_depends_only_on_approved_collaborators(): void
    {
        $constructor = (new ReflectionClass(NotificationService::class))->getConstructor();

        $types = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $types[] = $type->getName();
        }
        sort($types);

        $expected = [
            AuditLogger::class,
            NotificationProviderInterface::class,
            NotificationRepositoryInterface::class,
        ];
        sort($expected);

        $this->assertSame($expected, $types);
    }

    public function test_service_never_touches_models_or_the_query_builder_directly(): void
    {
        $code = $this->source(NotificationService::class);

        foreach ([
            'DB::table(', 'DB::select(', 'DB::statement(',
            'Notification::query(', 'Notification::create(', 'Notification::find(',
            'Reservation::query(', 'Guest::query(',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $code, "must not use {$needle}");
        }
    }

    public function test_service_never_references_the_concrete_dummy_provider(): void
    {
        $this->assertStringNotContainsString('DummyNotificationProvider', $this->source(NotificationService::class));
    }

    public function test_provider_is_called_exactly_once_and_the_workflow_is_staged(): void
    {
        $code = $this->source(NotificationService::class);

        // Exactly one provider call site (in deliverOnChannel, between the two
        // staged transactions). The runtime guard in NotificationServiceTest
        // proves it is not inside an open transaction.
        $this->assertSame(1, substr_count($code, '$this->provider->send('));
        $this->assertStringContainsString('DB::transaction(', $code);
        $this->assertStringContainsString('applyDeliveryResult(', $code);
    }

    public function test_listener_delegates_to_the_service_and_never_rolls_back_the_caller(): void
    {
        $code = $this->source(SendReservationLifecycleNotifications::class);

        $this->assertStringContainsString('dispatchForReservation(', $code);
        // A notification failure must never bubble into the committed transition.
        $this->assertStringContainsString('catch (Throwable', $code);
        $this->assertStringNotContainsString('DB::', $code);
    }

    public function test_reservation_service_has_no_dependency_on_the_notification_domain(): void
    {
        $code = file_get_contents((new ReflectionClass(ReservationService::class))->getFileName());

        $this->assertStringNotContainsString('App\Domain\Notification', $code);
        // It only emits a same-domain event; it never knows a listener exists.
        $this->assertStringContainsString('ReservationStatusChanged::dispatch(', $code);
    }
}
