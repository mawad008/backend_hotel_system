<?php

namespace Tests\Unit\IdentityVerification\Provider;

use App\Domain\IdentityVerification\Provider\Contracts\IdentityVerificationProviderInterface;
use App\Domain\IdentityVerification\Provider\Data\VerificationRequest;
use App\Domain\IdentityVerification\Provider\Data\VerificationResult;
use App\Domain\IdentityVerification\Provider\DummyIdentityVerificationProvider;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Phase 6 — architectural guardrails. The provider is a pure external
 * adapter: no persistence, models, repositories, container, clock or
 * randomness.
 */
class DummyIdentityVerificationProviderArchitectureTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function providerSourceFiles(): array
    {
        $dir = dirname((new ReflectionClass(DummyIdentityVerificationProvider::class))->getFileName());

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
            'identity verification models' => ['IdentityVerification\Models'],
            'identity verification repositories' => ['IdentityVerification\Repositories'],
            'the identity verification state machine' => ['IdentityVerification\StateMachine'],
            'the identity verification service' => ['IdentityVerification\Services'],
            'the Reservation domain' => ['Domain\Reservation'],
            'Eloquent / the query builder' => ['Illuminate\Database', 'Eloquent', 'DB::', 'Facades\DB'],
            'the audit logger' => ['Domain\Audit'],
            'the service container' => ['app(', 'App::make', 'resolve('],
            'HTTP clients' => ['Facades\Http', 'GuzzleHttp', 'curl_'],
            'the clock' => ['now()', 'Carbon', 'time()', 'microtime', 'strtotime'],
            'randomness' => ['rand(', 'mt_rand', 'random_int', 'random_bytes', 'uniqid', 'Str::random', 'Str::uuid'],
            'the filesystem' => ['Storage::', 'file_put_contents', 'fopen('],
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
                // The real OCR adapter is a network client by definition: it
                // may use an (injected) HTTP client and a polling deadline —
                // every other boundary rule still applies to it.
                if ($short === 'AzureDocumentIntelligenceProvider.php' && in_array($label, ['HTTP clients', 'the clock'], true)) {
                    continue;
                }

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

    public function test_constructor_depends_only_on_the_directive_enum(): void
    {
        $constructor = (new ReflectionClass(DummyIdentityVerificationProvider::class))->getConstructor();

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type);

            $this->assertTrue(
                $type->isBuiltin() || $type->getName() === 'App\Domain\IdentityVerification\Provider\SimulationDirective',
                "Constructor param \${$parameter->getName()} must be a primitive or the SimulationDirective enum",
            );
        }
    }

    public function test_verify_issues_no_database_query(): void
    {
        $provider = new DummyIdentityVerificationProvider;

        DB::enableQueryLog();
        DB::flushQueryLog();

        $provider->verify(new VerificationRequest('attempt-1'));

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries);
    }

    public function test_interface_exposes_exactly_the_approved_operation_set(): void
    {
        $methods = array_map(
            fn (ReflectionMethod $m) => $m->getName(),
            (new ReflectionClass(IdentityVerificationProviderInterface::class))->getMethods(),
        );

        $this->assertSame(['verify'], $methods);
    }

    public function test_interface_operation_accepts_and_returns_dtos(): void
    {
        $method = new ReflectionMethod(IdentityVerificationProviderInterface::class, 'verify');

        $this->assertSame(VerificationRequest::class, $method->getParameters()[0]->getType()->getName());
        $this->assertSame(VerificationResult::class, $method->getReturnType()->getName());
    }
}
