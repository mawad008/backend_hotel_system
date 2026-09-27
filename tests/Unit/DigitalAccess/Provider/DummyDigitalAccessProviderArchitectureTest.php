<?php

namespace Tests\Unit\DigitalAccess\Provider;

use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\DigitalAccess\Provider\Contracts\DigitalAccessProviderInterface;
use App\Domain\DigitalAccess\Provider\Data\AccessIssueRequest;
use App\Domain\DigitalAccess\Provider\Data\AccessOperationRequest;
use App\Domain\DigitalAccess\Provider\Data\AccessResult;
use App\Domain\DigitalAccess\Provider\DummyDigitalAccessProvider;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Phase 7 — architectural guardrails. The provider is a pure external
 * adapter: no persistence, models, repositories, container, clock,
 * randomness, or authorization/eligibility logic (§11).
 */
class DummyDigitalAccessProviderArchitectureTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function providerSourceFiles(): array
    {
        $dir = dirname((new ReflectionClass(DummyDigitalAccessProvider::class))->getFileName());

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
            'digital access models' => ['DigitalAccess\Models'],
            'digital access repositories' => ['DigitalAccess\Repositories'],
            'the digital access state machine' => ['DigitalAccess\StateMachine'],
            'the digital access service' => ['DigitalAccess\Services'],
            'the Reservation / Payment / Identity domains' => ['Domain\Reservation', 'Domain\Payment', 'Domain\IdentityVerification'],
            'Eloquent / the query builder' => ['Illuminate\Database', 'Eloquent', 'DB::', 'Facades\DB'],
            'the audit logger' => ['Domain\Audit'],
            'authorization' => ['Gate::', 'HotelAccess', 'Policy', 'authorize'],
            'the service container' => ['app(', 'App::make', 'resolve('],
            'HTTP clients' => ['Facades\Http', 'GuzzleHttp', 'curl_'],
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
        $constructor = (new ReflectionClass(DummyDigitalAccessProvider::class))->getConstructor();

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $this->assertTrue(
                $type->isBuiltin() || $type->getName() === 'App\Domain\DigitalAccess\Provider\SimulationDirective',
                "Constructor param \${$parameter->getName()} must be a primitive or the SimulationDirective enum",
            );
        }
    }

    public function test_operations_issue_no_database_query(): void
    {
        $provider = new DummyDigitalAccessProvider;

        DB::enableQueryLog();
        DB::flushQueryLog();

        $provider->issue(new AccessIssueRequest('g', AccessGrant::MODE_PIN_CODE));
        $provider->revoke(new AccessOperationRequest('dummy_access_issue_x'));

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries);
    }

    public function test_interface_exposes_exactly_the_approved_operation_set(): void
    {
        $methods = array_map(
            fn (ReflectionMethod $m) => $m->getName(),
            (new ReflectionClass(DigitalAccessProviderInterface::class))->getMethods(),
        );
        sort($methods);

        $this->assertSame(['issue', 'revoke'], $methods);
    }

    public function test_interface_operations_accept_and_return_dtos(): void
    {
        $expected = [
            'issue' => [AccessIssueRequest::class, AccessResult::class],
            'revoke' => [AccessOperationRequest::class, AccessResult::class],
        ];

        foreach ($expected as $method => [$param, $return]) {
            $reflected = new ReflectionMethod(DigitalAccessProviderInterface::class, $method);
            $this->assertSame($param, $reflected->getParameters()[0]->getType()->getName());
            $this->assertSame($return, $reflected->getReturnType()->getName());
        }
    }
}
