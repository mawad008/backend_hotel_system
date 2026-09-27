<?php

namespace Tests\Unit\Payment\Gateway;

use App\Domain\Payment\Gateway\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Gateway\Data\GatewayHoldRequest;
use App\Domain\Payment\Gateway\Data\GatewayOperationRequest;
use App\Domain\Payment\Gateway\Data\GatewayResult;
use App\Domain\Payment\Gateway\Data\NormalizedWebhook;
use App\Domain\Payment\Gateway\DummyPaymentGateway;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Phase 5B §21 — architectural guardrails. The gateway is a pure external
 * provider adapter: it must not reach into persistence, the Payment /
 * Reservation models, repositories, the container, or the clock.
 */
class DummyPaymentGatewayArchitectureTest extends TestCase
{
    /**
     * Every PHP source file that makes up the gateway boundary.
     *
     * @return list<string>
     */
    private function gatewaySourceFiles(): array
    {
        $dir = dirname((new ReflectionClass(DummyPaymentGateway::class))->getFileName());

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

    /**
     * The file's PHP source with every comment and docblock removed, so a
     * guardrail scan only sees real code — a docblock is allowed to say
     * "no Eloquent here".
     */
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
    private function forbiddenReferencesBySymbol(): array
    {
        return [
            'the Payment model' => ['Payment\Models\Payment', 'Payment::'],
            'the PaymentTransaction model' => ['Payment\Models\PaymentTransaction'],
            'the PaymentWebhookEvent model' => ['Payment\Models\PaymentWebhookEvent'],
            'the Reservation domain' => ['Domain\Reservation'],
            'payment repositories' => ['Payment\Repositories'],
            'the payment state machine' => ['Payment\StateMachine'],
            'Eloquent / the query builder' => ['Illuminate\Database', 'Eloquent', 'DB::', 'Facades\DB'],
            'the audit logger' => ['Domain\Audit'],
            'the service container' => ['app(', 'App::make', 'resolve('],
            'HTTP clients' => ['Facades\Http', 'GuzzleHttp', 'curl_'],
            'the clock' => ['now()', 'Carbon', 'time()', 'microtime', 'strtotime'],
            'randomness' => ['rand(', 'mt_rand', 'random_int', 'random_bytes', 'uniqid', 'Str::random', 'Str::uuid'],
        ];
    }

    public function test_gateway_source_has_no_forbidden_dependency(): void
    {
        $files = $this->gatewaySourceFiles();
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $code = $this->codeWithoutComments($file);
            $short = basename($file);

            foreach ($this->forbiddenReferencesBySymbol() as $label => $needles) {
                foreach ($needles as $needle) {
                    $this->assertStringNotContainsString(
                        $needle,
                        $code,
                        "{$short} must not reference {$label} (found '{$needle}')",
                    );
                }
            }
        }
    }

    public function test_dummy_gateway_constructor_depends_only_on_primitives(): void
    {
        $constructor = (new ReflectionClass(DummyPaymentGateway::class))->getConstructor();

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type);

            $this->assertTrue(
                $type->isBuiltin() || $type->getName() === 'App\Domain\Payment\Gateway\SimulationDirective',
                "Constructor param \${$parameter->getName()} must be a primitive or the SimulationDirective enum",
            );
        }
    }

    public function test_no_gateway_method_signature_references_a_model_or_repository(): void
    {
        $class = new ReflectionClass(DummyPaymentGateway::class);
        $types = [];

        foreach ($class->getMethods() as $method) {
            $return = $method->getReturnType();
            if ($return instanceof ReflectionNamedType && ! $return->isBuiltin()) {
                $types[] = $return->getName();
            }

            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                    $types[] = $type->getName();
                }
            }
        }

        foreach (array_unique($types) as $fqcn) {
            $this->assertStringNotContainsString('\Models\\', $fqcn, "{$fqcn} is a model");
            $this->assertStringNotContainsString('\Repositories\\', $fqcn, "{$fqcn} is a repository");
            $this->assertStringStartsWith('App\Domain\Payment\Gateway\\', $fqcn);
        }
    }

    public function test_gateway_calling_every_operation_issues_no_database_query(): void
    {
        $gateway = new DummyPaymentGateway('secret');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $gateway->initiateHold(new GatewayHoldRequest('pay_1', '10.00'));
        $gateway->cancelHold(new GatewayOperationRequest('dummy_hold_x'));
        $gateway->capture(new GatewayOperationRequest('dummy_hold_x'));
        $gateway->settle(new GatewayOperationRequest('dummy_hold_x'));
        $gateway->verify(new GatewayOperationRequest('dummy_hold_x'));
        $gateway->parseWebhook(json_encode(['type' => 'hold.succeeded']));
        $gateway->verifySignature('{}', 'x');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries);
    }

    // ---- The contract works with DTOs, not raw provider arrays ---------

    public function test_interface_operations_accept_and_return_dtos_not_arrays(): void
    {
        $expected = [
            'initiateHold' => [GatewayHoldRequest::class, GatewayResult::class],
            'cancelHold' => [GatewayOperationRequest::class, GatewayResult::class],
            'capture' => [GatewayOperationRequest::class, GatewayResult::class],
            'settle' => [GatewayOperationRequest::class, GatewayResult::class],
            'verify' => [GatewayOperationRequest::class, GatewayResult::class],
            'parseWebhook' => ['string', NormalizedWebhook::class],
        ];

        foreach ($expected as $method => [$paramType, $returnType]) {
            $reflected = new ReflectionMethod(PaymentGatewayInterface::class, $method);

            $this->assertSame(
                $paramType,
                $reflected->getParameters()[0]->getType()->getName(),
                "{$method} first parameter type",
            );
            $this->assertSame(
                $returnType,
                $reflected->getReturnType()->getName(),
                "{$method} return type",
            );
        }
    }

    public function test_interface_exposes_exactly_the_approved_operation_set(): void
    {
        $methods = array_map(
            fn (ReflectionMethod $m) => $m->getName(),
            (new ReflectionClass(PaymentGatewayInterface::class))->getMethods(),
        );

        $this->assertNotContains('refund', $methods);
        $this->assertEqualsCanonicalizing(
            ['initiateHold', 'cancelHold', 'capture', 'settle', 'verify', 'parseWebhook', 'verifySignature'],
            $methods,
        );
    }
}
