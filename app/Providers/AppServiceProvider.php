<?php

namespace App\Providers;

use App\Domain\AppContent\Models\GuestAppContent;
use App\Domain\AppContent\Policies\GuestAppContentPolicy;
use App\Domain\AppContent\Repositories\Contracts\GuestAppContentRepositoryInterface;
use App\Domain\AppContent\Repositories\EloquentGuestAppContentRepository;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Policies\AuditLogPolicy;
use App\Domain\Audit\Repositories\Contracts\AuditLogRepositoryInterface;
use App\Domain\Audit\Repositories\EloquentAuditLogRepository;
use App\Domain\Checkout\Models\Checkout;
use App\Domain\Checkout\Models\Invoice;
use App\Domain\Checkout\Policies\CheckoutPolicy;
use App\Domain\Checkout\Policies\InvoicePolicy;
use App\Domain\Checkout\Repositories\Contracts\CheckoutRepositoryInterface;
use App\Domain\Checkout\Repositories\Contracts\InvoiceRepositoryInterface;
use App\Domain\Checkout\Repositories\EloquentCheckoutRepository;
use App\Domain\Checkout\Repositories\EloquentInvoiceRepository;
use App\Domain\DigitalAccess\Listeners\RevokeAccessWhenStayEnds;
use App\Domain\DigitalAccess\Models\AccessGrant;
use App\Domain\DigitalAccess\Policies\AccessGrantPolicy;
use App\Domain\DigitalAccess\Provider\Contracts\DigitalAccessProviderInterface;
use App\Domain\DigitalAccess\Provider\DummyDigitalAccessProvider;
use App\Domain\DigitalAccess\Provider\Exceptions\UnsupportedDigitalAccessProviderException;
use App\Domain\DigitalAccess\Provider\SimulationDirective as DigitalAccessSimulationDirective;
use App\Domain\DigitalAccess\Repositories\Contracts\AccessGrantRepositoryInterface;
use App\Domain\DigitalAccess\Repositories\EloquentAccessGrantRepository;
use App\Domain\Discovery\Repositories\Contracts\GuestFavoriteHotelRepositoryInterface;
use App\Domain\Discovery\Repositories\Contracts\HotelCatalogRepositoryInterface;
use App\Domain\Discovery\Repositories\EloquentGuestFavoriteHotelRepository;
use App\Domain\Discovery\Repositories\EloquentHotelCatalogRepository;
use App\Domain\GuestAccess\Otp\Contracts\OtpSenderInterface;
use App\Domain\GuestAccess\Otp\DummyOtpSender;
use App\Domain\GuestAccess\Otp\Exceptions\UnsupportedOtpSenderException;
use App\Domain\GuestAccess\Repositories\Contracts\GuestOtpChallengeRepositoryInterface;
use App\Domain\GuestAccess\Repositories\EloquentGuestOtpChallengeRepository;
use App\Domain\HotelGroup\Models\Facility;
use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\HotelGroup\Models\HotelGroup;
use App\Domain\HotelGroup\Policies\FacilityPolicy;
use App\Domain\HotelGroup\Policies\HotelGroupPolicy;
use App\Domain\HotelGroup\Policies\HotelPolicy;
use App\Domain\HotelGroup\Repositories\Contracts\FacilityRepositoryInterface;
use App\Domain\HotelGroup\Repositories\Contracts\HotelGroupRepositoryInterface;
use App\Domain\HotelGroup\Repositories\Contracts\HotelRepositoryInterface;
use App\Domain\HotelGroup\Repositories\EloquentFacilityRepository;
use App\Domain\HotelGroup\Repositories\EloquentHotelGroupRepository;
use App\Domain\HotelGroup\Repositories\EloquentHotelRepository;
use App\Domain\IdentityAccess\Models\Role;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Policies\RolePolicy;
use App\Domain\IdentityAccess\Policies\UserPolicy;
use App\Domain\IdentityAccess\Repositories\Contracts\PermissionRepositoryInterface;
use App\Domain\IdentityAccess\Repositories\Contracts\RoleRepositoryInterface;
use App\Domain\IdentityAccess\Repositories\Contracts\UserRepositoryInterface;
use App\Domain\IdentityAccess\Repositories\EloquentPermissionRepository;
use App\Domain\IdentityAccess\Repositories\EloquentRoleRepository;
use App\Domain\IdentityAccess\Repositories\EloquentUserRepository;
use App\Domain\IdentityVerification\Models\IdentityVerificationSession;
use App\Domain\IdentityVerification\Policies\IdentityVerificationPolicy;
use App\Domain\IdentityVerification\Provider\AzureDocumentIntelligenceProvider;
use App\Domain\IdentityVerification\Provider\Contracts\IdentityDocumentProviderInterface;
use App\Domain\IdentityVerification\Provider\Contracts\IdentityVerificationProviderInterface;
use App\Domain\IdentityVerification\Provider\DummyIdentityDocumentProvider;
use App\Domain\IdentityVerification\Provider\DocumentProviderRouter;
use App\Domain\IdentityVerification\DocumentCheck\IdentityDocumentCatalog;
use Illuminate\Http\Client\Factory as HttpFactory;
use App\Domain\IdentityVerification\Provider\DummyIdentityVerificationProvider;
use App\Domain\IdentityVerification\Provider\Exceptions\UnsupportedIdentityVerificationProviderException;
use App\Domain\IdentityVerification\Provider\SimulationDirective as IdentityVerificationSimulationDirective;
use App\Domain\IdentityVerification\Repositories\Contracts\IdentityVerificationAttemptRepositoryInterface;
use App\Domain\IdentityVerification\Repositories\Contracts\IdentityVerificationDecisionRepositoryInterface;
use App\Domain\IdentityVerification\Repositories\Contracts\IdentityVerificationSessionRepositoryInterface;
use App\Domain\IdentityVerification\Repositories\EloquentIdentityVerificationAttemptRepository;
use App\Domain\IdentityVerification\Repositories\EloquentIdentityVerificationDecisionRepository;
use App\Domain\IdentityVerification\Repositories\EloquentIdentityVerificationSessionRepository;
use App\Domain\Inventory\Models\Room;
use App\Domain\Inventory\Models\RoomType;
use App\Domain\Inventory\Policies\RoomPolicy;
use App\Domain\Inventory\Policies\RoomTypePolicy;
use App\Domain\Inventory\Repositories\Contracts\RoomRepositoryInterface;
use App\Domain\Inventory\Repositories\Contracts\RoomTypeRepositoryInterface;
use App\Domain\Inventory\Repositories\EloquentRoomRepository;
use App\Domain\Inventory\Repositories\EloquentRoomTypeRepository;
use App\Domain\Location\Models\City;
use App\Domain\Location\Models\Country;
use App\Domain\Location\Policies\CityPolicy;
use App\Domain\Location\Policies\CountryPolicy;
use App\Domain\Location\Repositories\Contracts\CityRepositoryInterface;
use App\Domain\Location\Repositories\Contracts\CountryRepositoryInterface;
use App\Domain\Location\Repositories\EloquentCityRepository;
use App\Domain\Location\Repositories\EloquentCountryRepository;
use App\Domain\Loyalty\Listeners\AccrueLoyaltyOnStayCompletion;
use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Models\LoyaltyRule;
use App\Domain\Loyalty\Policies\LoyaltyPolicy;
use App\Domain\Loyalty\Policies\LoyaltyRulePolicy;
use App\Domain\Loyalty\Repositories\Contracts\LoyaltyAccountRepositoryInterface;
use App\Domain\Loyalty\Repositories\Contracts\LoyaltyRuleRepositoryInterface;
use App\Domain\Loyalty\Repositories\Contracts\LoyaltyTransactionRepositoryInterface;
use App\Domain\Loyalty\Repositories\EloquentLoyaltyAccountRepository;
use App\Domain\Loyalty\Repositories\EloquentLoyaltyRuleRepository;
use App\Domain\Loyalty\Repositories\EloquentLoyaltyTransactionRepository;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Policies\NotificationPolicy;
use App\Domain\Notification\Repositories\Contracts\NotificationRepositoryInterface;
use App\Domain\Notification\Repositories\EloquentNotificationRepository;
use App\Domain\Payment\Gateway\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Gateway\DummyPaymentGateway;
use App\Domain\Payment\Gateway\Exceptions\UnsupportedPaymentProviderException;
use App\Domain\Payment\Gateway\SimulationDirective;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Policies\PaymentPolicy;
use App\Domain\Payment\Repositories\Contracts\PaymentRepositoryInterface;
use App\Domain\Payment\Repositories\Contracts\PaymentTransactionRepositoryInterface;
use App\Domain\Payment\Repositories\Contracts\PaymentWebhookEventRepositoryInterface;
use App\Domain\Payment\Repositories\EloquentPaymentRepository;
use App\Domain\Payment\Repositories\EloquentPaymentTransactionRepository;
use App\Domain\Payment\Repositories\EloquentPaymentWebhookEventRepository;
use App\Domain\Reservation\Events\ReservationStatusChanged;
use App\Domain\Reservation\Models\Guest;
use App\Domain\Reservation\Models\Reservation;
use App\Domain\Reservation\Policies\GuestPolicy;
use App\Domain\Reservation\Policies\ReservationPolicy;
use App\Domain\Reservation\Repositories\Contracts\GuestRepositoryInterface;
use App\Domain\Reservation\Repositories\Contracts\ReservationExtensionRepositoryInterface;
use App\Domain\Reservation\Repositories\Contracts\ReservationRepositoryInterface;
use App\Domain\Reservation\Repositories\EloquentGuestRepository;
use App\Domain\Reservation\Repositories\EloquentReservationExtensionRepository;
use App\Domain\Reservation\Repositories\EloquentReservationRepository;
use App\Domain\Review\Models\Review;
use App\Domain\Review\Models\ReviewCategory;
use App\Domain\Review\Policies\ReviewCategoryPolicy;
use App\Domain\Review\Policies\ReviewPolicy;
use App\Domain\Review\Repositories\Contracts\ReviewCategoryRepositoryInterface;
use App\Domain\Review\Repositories\Contracts\ReviewRepositoryInterface;
use App\Domain\Review\Repositories\EloquentReviewCategoryRepository;
use App\Domain\Review\Repositories\EloquentReviewRepository;
use App\Domain\StayServices\Models\HotelService;
use App\Domain\StayServices\Models\ServiceCategory;
use App\Domain\StayServices\Models\ServiceOrder;
use App\Domain\StayServices\Models\ServiceReview;
use App\Domain\StayServices\Policies\FolioPolicy;
use App\Domain\StayServices\Policies\HotelServicePolicy;
use App\Domain\StayServices\Policies\ServiceCategoryPolicy;
use App\Domain\StayServices\Policies\ServiceOrderPolicy;
use App\Domain\StayServices\Policies\ServiceReviewPolicy;
use App\Domain\StayServices\Repositories\Contracts\FolioChargeRepositoryInterface;
use App\Domain\StayServices\Repositories\Contracts\HotelServiceRepositoryInterface;
use App\Domain\StayServices\Repositories\Contracts\ServiceCategoryRepositoryInterface;
use App\Domain\StayServices\Repositories\Contracts\ServiceOrderRepositoryInterface;
use App\Domain\StayServices\Repositories\Contracts\ServiceReviewRepositoryInterface;
use App\Domain\StayServices\Repositories\EloquentFolioChargeRepository;
use App\Domain\StayServices\Repositories\EloquentHotelServiceRepository;
use App\Domain\StayServices\Repositories\EloquentServiceCategoryRepository;
use App\Domain\StayServices\Repositories\EloquentServiceOrderRepository;
use App\Domain\StayServices\Repositories\EloquentServiceReviewRepository;
use App\Domain\StayServices\Services\Folio;
use App\Domain\Support\Models\ProblemReport;
use App\Domain\Support\Policies\ProblemReportPolicy;
use App\Domain\Support\Repositories\Contracts\ProblemReportRepositoryInterface;
use App\Domain\Support\Repositories\EloquentProblemReportRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        UserRepositoryInterface::class => EloquentUserRepository::class,
        RoleRepositoryInterface::class => EloquentRoleRepository::class,
        PermissionRepositoryInterface::class => EloquentPermissionRepository::class,
        HotelGroupRepositoryInterface::class => EloquentHotelGroupRepository::class,
        HotelRepositoryInterface::class => EloquentHotelRepository::class,
        FacilityRepositoryInterface::class => EloquentFacilityRepository::class,
        RoomTypeRepositoryInterface::class => EloquentRoomTypeRepository::class,
        RoomRepositoryInterface::class => EloquentRoomRepository::class,
        ReservationRepositoryInterface::class => EloquentReservationRepository::class,
        ReservationExtensionRepositoryInterface::class => EloquentReservationExtensionRepository::class,
        GuestRepositoryInterface::class => EloquentGuestRepository::class,
        GuestOtpChallengeRepositoryInterface::class => EloquentGuestOtpChallengeRepository::class,
        HotelCatalogRepositoryInterface::class => EloquentHotelCatalogRepository::class,
        GuestFavoriteHotelRepositoryInterface::class => EloquentGuestFavoriteHotelRepository::class,
        CountryRepositoryInterface::class => EloquentCountryRepository::class,
        CityRepositoryInterface::class => EloquentCityRepository::class,
        PaymentRepositoryInterface::class => EloquentPaymentRepository::class,
        PaymentTransactionRepositoryInterface::class => EloquentPaymentTransactionRepository::class,
        PaymentWebhookEventRepositoryInterface::class => EloquentPaymentWebhookEventRepository::class,
        IdentityVerificationSessionRepositoryInterface::class => EloquentIdentityVerificationSessionRepository::class,
        IdentityVerificationAttemptRepositoryInterface::class => EloquentIdentityVerificationAttemptRepository::class,
        IdentityVerificationDecisionRepositoryInterface::class => EloquentIdentityVerificationDecisionRepository::class,
        AccessGrantRepositoryInterface::class => EloquentAccessGrantRepository::class,
        ServiceCategoryRepositoryInterface::class => EloquentServiceCategoryRepository::class,
        HotelServiceRepositoryInterface::class => EloquentHotelServiceRepository::class,
        ServiceOrderRepositoryInterface::class => EloquentServiceOrderRepository::class,
        ServiceReviewRepositoryInterface::class => EloquentServiceReviewRepository::class,
        FolioChargeRepositoryInterface::class => EloquentFolioChargeRepository::class,
        CheckoutRepositoryInterface::class => EloquentCheckoutRepository::class,
        InvoiceRepositoryInterface::class => EloquentInvoiceRepository::class,
        LoyaltyAccountRepositoryInterface::class => EloquentLoyaltyAccountRepository::class,
        LoyaltyTransactionRepositoryInterface::class => EloquentLoyaltyTransactionRepository::class,
        LoyaltyRuleRepositoryInterface::class => EloquentLoyaltyRuleRepository::class,
        NotificationRepositoryInterface::class => EloquentNotificationRepository::class,
        ReviewRepositoryInterface::class => EloquentReviewRepository::class,
        ReviewCategoryRepositoryInterface::class => EloquentReviewCategoryRepository::class,
        AuditLogRepositoryInterface::class => EloquentAuditLogRepository::class,
        ProblemReportRepositoryInterface::class => EloquentProblemReportRepository::class,
        GuestAppContentRepositoryInterface::class => EloquentGuestAppContentRepository::class,
    ];

    /**
     * @var array<class-string, class-string>
     */
    public array $policies = [
        HotelGroup::class => HotelGroupPolicy::class,
        GuestAppContent::class => GuestAppContentPolicy::class,
        Hotel::class => HotelPolicy::class,
        Facility::class => FacilityPolicy::class,
        Country::class => CountryPolicy::class,
        City::class => CityPolicy::class,
        User::class => UserPolicy::class,
        Role::class => RolePolicy::class,
        RoomType::class => RoomTypePolicy::class,
        Room::class => RoomPolicy::class,
        Reservation::class => ReservationPolicy::class,
        Payment::class => PaymentPolicy::class,
        IdentityVerificationSession::class => IdentityVerificationPolicy::class,
        AccessGrant::class => AccessGrantPolicy::class,
        ServiceCategory::class => ServiceCategoryPolicy::class,
        HotelService::class => HotelServicePolicy::class,
        ServiceOrder::class => ServiceOrderPolicy::class,
        ServiceReview::class => ServiceReviewPolicy::class,
        Folio::class => FolioPolicy::class,
        Checkout::class => CheckoutPolicy::class,
        Invoice::class => InvoicePolicy::class,
        LoyaltyAccount::class => LoyaltyPolicy::class,
        LoyaltyRule::class => LoyaltyRulePolicy::class,
        Notification::class => NotificationPolicy::class,
        Review::class => ReviewPolicy::class,
        ReviewCategory::class => ReviewCategoryPolicy::class,
        AuditLog::class => AuditLogPolicy::class,
        Guest::class => GuestPolicy::class,
        ProblemReport::class => ProblemReportPolicy::class,
    ];

    public function register(): void
    {
        // The OTP sender boundary (Slice 0). The app depends only on
        // OtpSenderInterface; the concrete sender is chosen from
        // config('otp.sender'). "dummy" is the only implementation — an
        // explicitly configured but unsupported sender fails loudly.
        // Room Detail content reads the facility catalog once per request.
        $this->app->scoped(\App\Http\Resources\V1\Support\RoomDetailContent::class);

        $this->app->singleton(OtpSenderInterface::class, function (): OtpSenderInterface {
            $sender = (string) config('otp.sender');

            return match ($sender) {
                'dummy' => new DummyOtpSender,
                default => throw new UnsupportedOtpSenderException($sender),
            };
        });

        // The payment provider boundary (Phase 5B). The rest of the app
        // depends only on PaymentGatewayInterface; the concrete provider is
        // chosen from config('payment.provider'). "dummy" is the only
        // implementation registered this phase — an explicitly configured
        // but unsupported provider fails loudly, never silently falls back.
        $this->app->singleton(PaymentGatewayInterface::class, function (): PaymentGatewayInterface {
            $provider = (string) config('payment.provider');

            return match ($provider) {
                'dummy' => new DummyPaymentGateway(
                    webhookSecret: (string) config('payment.providers.dummy.webhook_secret', ''),
                    defaultDirective: SimulationDirective::fromConfig(
                        config('payment.providers.dummy.default_directive'),
                    ),
                ),
                default => throw new UnsupportedPaymentProviderException($provider),
            };
        });

        // The identity verification provider boundary (Phase 6). The rest of
        // the app depends only on IdentityVerificationProviderInterface; the
        // concrete provider is chosen from config('verification.provider').
        // "dummy" is the only implementation registered this phase — an
        // explicitly configured but unsupported provider fails loudly, never
        // silently falls back.
        $this->app->singleton(IdentityVerificationProviderInterface::class, function (): IdentityVerificationProviderInterface {
            $provider = (string) config('verification.provider');

            return match ($provider) {
                'dummy' => new DummyIdentityVerificationProvider(
                    defaultDirective: IdentityVerificationSimulationDirective::fromConfig(
                        config('verification.providers.dummy.default_directive'),
                    ),
                ),
                default => throw new UnsupportedIdentityVerificationProviderException($provider),
            };
        });

        // The OCR document provider boundary. Production must use the real
        // provider: the synthetic dummy is refused there outright.
        $this->app->singleton(IdentityDocumentProviderInterface::class, function (): IdentityDocumentProviderInterface {
            $provider = (string) config('verification.document_provider');

            return match ($provider) {
                // Routed by document type; the router has no path to the dummy.
                AzureDocumentIntelligenceProvider::NAME => new DocumentProviderRouter(new AzureDocumentIntelligenceProvider(
                    http: $this->app->make(HttpFactory::class),
                    endpoint: (string) config('verification.document_providers.azure_document_intelligence.endpoint'),
                    key: (string) config('verification.document_providers.azure_document_intelligence.key'),
                    apiVersion: (string) config('verification.document_providers.azure_document_intelligence.api_version'),
                    model: (string) config('verification.document_providers.azure_document_intelligence.model'),
                    timeoutSeconds: (int) config('verification.document_providers.azure_document_intelligence.timeout_seconds'),
                    pollIntervalMs: (int) config('verification.document_providers.azure_document_intelligence.poll_interval_ms'),
                ), new IdentityDocumentCatalog),
                DummyIdentityDocumentProvider::NAME => $this->app->environment('production')
                    ? throw new UnsupportedIdentityVerificationProviderException('dummy (not allowed in production)')
                    : new DummyIdentityDocumentProvider((string) config('verification.document_providers.dummy.scenario')),
                default => throw new UnsupportedIdentityVerificationProviderException($provider),
            };
        });

        // The digital access provider boundary (Phase 7). The rest of the app
        // depends only on DigitalAccessProviderInterface; the concrete
        // provider is chosen from config('digital_access.provider'). "dummy"
        // is the only implementation registered this phase — an explicitly
        // configured but unsupported provider fails loudly, never silently
        // falls back.
        $this->app->singleton(DigitalAccessProviderInterface::class, function (): DigitalAccessProviderInterface {
            $provider = (string) config('digital_access.provider');

            return match ($provider) {
                'dummy' => new DummyDigitalAccessProvider(
                    defaultDirective: DigitalAccessSimulationDirective::fromConfig(
                        config('digital_access.providers.dummy.default_directive'),
                    ),
                ),
                default => throw new UnsupportedDigitalAccessProviderException($provider),
            };
        });
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // Lifecycle-driven loyalty accrual on stay completion (idempotent).
        Event::listen(ReservationStatusChanged::class, AccrueLoyaltyOnStayCompletion::class);
        // A departed / cancelled stay's room credential stops working.
        Event::listen(ReservationStatusChanged::class, RevokeAccessWhenStayEnds::class);

        Gate::define('permissions.view', fn (User $user) => $user->hasPermission('permissions.view'));
        Gate::define('reports.view', fn (User $user) => $user->hasPermission('reports.view'));

        // A staff token stops authenticating the moment its account is
        // deactivated (login already refuses inactive accounts).
        Sanctum::authenticateAccessTokensUsing(
            fn ($accessToken, bool $isValid): bool => $isValid
                && ! ($accessToken->tokenable instanceof User && ! $accessToken->tokenable->is_active),
        );

        $this->registerGuestAuthRateLimiters();
        $this->registerPaymentRateLimiters();
        $this->registerIdentityVerificationRateLimiters();
        $this->registerDigitalAccessRateLimiters();
        $this->registerCheckoutRateLimiters();
        $this->registerNotificationRateLimiters();
        $this->registerHotelMediaRateLimiters();
        $this->registerRoomMediaRateLimiters();
        $this->registerGuestBookingRateLimiters();
    }

    /**
     * Rate limiting on the staff hotel-media upload endpoint. Config-driven
     * (config/hotel_media.php), keyed by authenticated user id (IP fallback).
     */
    private function registerHotelMediaRateLimiters(): void
    {
        RateLimiter::for('hotel-media.upload', fn (Request $request) => Limit::perMinute(
            (int) config('hotel_media.rate_limits.upload.per_minute'),
        )->by((string) ($request->user()?->id ?? $request->ip())));
    }

    /**
     * Rate limiting on the staff room/room-type media upload endpoints.
     * Config-driven (config/room_media.php), keyed by authenticated user id
     * (IP fallback). Mirrors registerHotelMediaRateLimiters().
     */
    private function registerRoomMediaRateLimiters(): void
    {
        RateLimiter::for('room-media.upload', fn (Request $request) => Limit::perMinute(
            (int) config('room_media.rate_limits.upload.per_minute'),
        )->by((string) ($request->user()?->id ?? $request->ip())));
    }

    /**
     * Rate limiting on the authenticated guest booking write endpoints
     * (reservation create / cancel, payment hold). Config-driven
     * (config/guest_booking.php), keyed by authenticated guest id
     * (IP fallback).
     */
    private function registerGuestBookingRateLimiters(): void
    {
        RateLimiter::for('guest.booking.write', fn (Request $request) => Limit::perMinute(
            (int) config('guest_booking.rate_limits.write.per_minute'),
        )->by((string) ($request->user()?->id ?? $request->ip())));
    }

    /**
     * Phase 0 §17 — rate limiting on the user-triggered notification-feed
     * endpoints (Phase 11). Config-driven (config/notifications.php), native
     * RateLimiter, no package. Keyed by authenticated user id (IP fallback).
     */
    private function registerNotificationRateLimiters(): void
    {
        RateLimiter::for('notifications.read', fn (Request $request) => Limit::perMinute(
            (int) config('notifications.rate_limits.read.per_minute'),
        )->by((string) ($request->user()?->id ?? $request->ip())));
    }

    /**
     * Phase 0 §17 — rate limiting on the financially-sensitive checkout
     * endpoint (Phase 9G). Config-driven (config/checkout.php), native
     * RateLimiter, no package. Keyed by authenticated user id (IP fallback),
     * conservative — a staff member never legitimately fires this many
     * checkouts a minute.
     */
    private function registerCheckoutRateLimiters(): void
    {
        RateLimiter::for('checkout.perform', fn (Request $request) => Limit::perMinute(
            (int) config('checkout.rate_limits.perform.per_minute'),
        )->by((string) ($request->user()?->id ?? $request->ip())));
    }

    /**
     * Phase 0 §17 — rate limiting on the unauthenticated guest OTP
     * endpoints (Slice 0). Config-driven (config/otp.php), keyed by the
     * submitted phone (IP fallback) so one number cannot be flooded and
     * one IP cannot farm codes for many numbers.
     */
    private function registerGuestAuthRateLimiters(): void
    {
        RateLimiter::for('guest.otp.request', fn (Request $request) => [
            Limit::perMinute(
                (int) config('otp.rate_limits.request.per_minute'),
            )->by((string) ($request->input('phone') ?: $request->ip())),
            Limit::perMinute(
                (int) config('otp.rate_limits.request.per_ip_per_minute'),
            )->by('ip:'.$request->ip()),
        ]);

        // Staff login — brute-force protection per account + client IP.
        RateLimiter::for('auth.login', fn (Request $request) => Limit::perMinute(
            (int) config('auth.login_rate_limit.per_minute'),
        )->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('guest.otp.verify', fn (Request $request) => Limit::perMinute(
            (int) config('otp.rate_limits.verify.per_minute'),
        )->by((string) ($request->input('phone') ?: $request->ip())));
    }

    /**
     * Phase 0 §17 — rate limiting on the payment endpoints (Phase 5F).
     * Config-driven (config/payment.php), native RateLimiter, no package.
     */
    private function registerPaymentRateLimiters(): void
    {
        RateLimiter::for('payments.hold', fn (Request $request) => Limit::perMinute(
            (int) config('payment.rate_limits.hold.per_minute'),
        )->by((string) ($request->user()?->id ?? $request->ip())));

        RateLimiter::for('payments.webhook', fn (Request $request) => Limit::perMinute(
            (int) config('payment.rate_limits.webhook.per_minute'),
        )->by((string) $request->ip()));
    }

    /**
     * Phase 0 §17 — "rate limiting on ... verification-upload ... endpoints"
     * (Phase 6). Config-driven (config/verification.php), native
     * RateLimiter, no package. Keyed by authenticated user id (IP fallback).
     */
    private function registerIdentityVerificationRateLimiters(): void
    {
        RateLimiter::for('identity-verification.submit', fn (Request $request) => Limit::perMinute(
            (int) config('verification.rate_limits.submit.per_minute'),
        )->by((string) ($request->user()?->id ?? $request->ip())));
    }

    /**
     * Phase 0 §17 — rate limiting on the sensitive check-in / digital access
     * endpoints (Phase 7). Config-driven (config/digital_access.php), native
     * RateLimiter, no package. Keyed by authenticated user id (IP fallback).
     */
    private function registerDigitalAccessRateLimiters(): void
    {
        RateLimiter::for('check-in', fn (Request $request) => Limit::perMinute(
            (int) config('digital_access.rate_limits.check_in.per_minute'),
        )->by((string) ($request->user()?->id ?? $request->ip())));

        RateLimiter::for('digital-access.revoke', fn (Request $request) => Limit::perMinute(
            (int) config('digital_access.rate_limits.revoke.per_minute'),
        )->by((string) ($request->user()?->id ?? $request->ip())));
    }
}
