<?php

namespace Tests\Unit\Notification\Provider;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Provider\Contracts\NotificationProviderInterface;
use App\Domain\Notification\Provider\Data\NotificationDeliveryResult;
use App\Domain\Notification\Provider\Data\NotificationDispatchRequest;
use App\Domain\Notification\Provider\DummyNotificationProvider;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Phase 11 — architectural guardrails. The provider is a pure external
 * adapter: no persistence, models, repositories, container, clock,
 * randomness, or authorization/recipient logic (§15).
 */
class DummyNotificationProviderArchitectureTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function providerSourceFiles(): array
    {
        $dir = dirname((new ReflectionClass(DummyNotificationProvider::class))->getFileName());

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function codeWithoutComments(string $file): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /**
     * @return array<string, list<string>>
     */
    private function forbiddenReferences(): array
    {
        return [
            'notification models' => ['Notification\Models'],
            'notification repositories' => ['Notification\Repositories'],
            'the notification state machine' => ['Notification\StateMachine'],
            'the notification service' => ['Notification\Services'],
            'the Reservation / Payment domains' => ['Domain\Reservation', 'Domain\Payment'],
            'Eloquent / the query builder' => ['Illuminate\Database', 'Eloquent', 'DB::', 'Facades\DB'],
            'the audit logger' => ['Domain\Audit'],
            'authorization' => ['Gate::', 'HotelAccess', 'Policy', 'authorize'],
            'the service container' => ['app(', 'App::make', 'resolve('],
            'HTTP clients' => ['Facades\Http', 'GuzzleHttp', 'curl_'],
            'mail / notification transports' => ['Facades\Mail', 'Facades\Notification', 'Illuminate\Notifications', 'Illuminate\Mail'],
            'the clock' => ['now()', 'Carbon', 'time()', 'microtime', 'strtotime'],
            'randomness' => ['rand(', 'mt_rand', 'random_int', 'random_bytes', 'uniqid', 'Str::random', 'Str::uuid'],
        ];
    }

    public function test_provider_source_has_no_forbidden_dependency(): void
    {
        $files = $this->providerSourceFiles();
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $code = $this->codeWithoutComments($file);
            $short = basename($file);
            foreach ($this->forbiddenReferences() as $label => $needles) {
                foreach ($needles as $needle) {
                    $this->assertStringNotContainsString($needle, $code, "{$short} must not reference {$label} (found '{$needle}')");
                }
            }
        }
    }

    public function test_constructor_depends_only_on_the_directive_enum(): void
    {
        $constructor = (new ReflectionClass(DummyNotificationProvider::class))->getConstructor();

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $this->assertTrue(
                $type->isBuiltin() || $type->getName() === 'App\Domain\Notification\Provider\SimulationDirective',
                "Constructor param \${$parameter->getName()} must be a primitive or the SimulationDirective enum",
            );
        }
    }

    public function test_send_issues_no_database_query(): void
    {
        $provider = new DummyNotificationProvider;

        DB::enableQueryLog();
        DB::flushQueryLog();

        foreach (NotificationChannel::cases() as $channel) {
            $provider->send(new NotificationDispatchRequest(
                channel: $channel,
                destinationReference: 'rcpt_x',
                type: 'reservation_deposit_held',
                locale: 'en',
                subject: 's',
                body: 'b',
            ));
        }

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries);
    }

    public function test_interface_exposes_exactly_the_approved_operation_set(): void
    {
        $methods = array_map(
            fn (ReflectionMethod $m) => $m->getName(),
            (new ReflectionClass(NotificationProviderInterface::class))->getMethods(),
        );
        sort($methods);

        $this->assertSame(['send'], $methods);
    }

    public function test_interface_operation_accepts_and_returns_dtos(): void
    {
        $reflected = new ReflectionMethod(NotificationProviderInterface::class, 'send');

        $this->assertSame(NotificationDispatchRequest::class, $reflected->getParameters()[0]->getType()->getName());
        $this->assertSame(NotificationDeliveryResult::class, $reflected->getReturnType()->getName());
    }
}
