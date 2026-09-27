<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CheckInController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\CityController;
use App\Http\Controllers\Api\V1\CountryController;
use App\Http\Controllers\Api\V1\DigitalAccessController;
use App\Http\Controllers\Api\V1\FacilityController;
use App\Http\Controllers\Api\V1\FolioController;
use App\Http\Controllers\Api\V1\Guest\GuestAppContentController as GuestAppContentReadController;
use App\Http\Controllers\Api\V1\Guest\GuestAuthController;
use App\Http\Controllers\Api\V1\Guest\GuestCheckInController;
use App\Http\Controllers\Api\V1\Guest\GuestCheckoutController;
use App\Http\Controllers\Api\V1\Guest\GuestDigitalAccessController;
use App\Http\Controllers\Api\V1\Guest\GuestDiscoveryController;
use App\Http\Controllers\Api\V1\Guest\GuestFavoriteHotelController;
use App\Http\Controllers\Api\V1\Guest\GuestFolioController;
use App\Http\Controllers\Api\V1\Guest\GuestIdentityVerificationController;
use App\Http\Controllers\Api\V1\Guest\GuestInvoiceController;
use App\Http\Controllers\Api\V1\Guest\GuestLoyaltyController;
use App\Http\Controllers\Api\V1\Guest\GuestNotificationController;
use App\Http\Controllers\Api\V1\Guest\GuestPaymentController;
use App\Http\Controllers\Api\V1\Guest\GuestProblemReportController;
use App\Http\Controllers\Api\V1\Guest\GuestReservationController;
use App\Http\Controllers\Api\V1\Guest\GuestReviewController;
use App\Http\Controllers\Api\V1\Guest\GuestServiceCatalogController;
use App\Http\Controllers\Api\V1\Guest\GuestServiceOrderController;
use App\Http\Controllers\Api\V1\Guest\GuestServiceReviewController;
use App\Http\Controllers\Api\V1\GuestAppContentController;
use App\Http\Controllers\Api\V1\GuestController;
use App\Http\Controllers\Api\V1\HotelController;
use App\Http\Controllers\Api\V1\HotelGroupController;
use App\Http\Controllers\Api\V1\HotelMediaController;
use App\Http\Controllers\Api\V1\IdentityVerificationController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\LoyaltyController;
use App\Http\Controllers\Api\V1\LoyaltyRuleController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\ProblemReportController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReservationController;
use App\Http\Controllers\Api\V1\ReviewCategoryController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\RoomController;
use App\Http\Controllers\Api\V1\RoomMediaController;
use App\Http\Controllers\Api\V1\RoomTypeController;
use App\Http\Controllers\Api\V1\RoomTypeMediaController;
use App\Http\Controllers\Api\V1\ServiceCategoryController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\ServiceOrderController;
use App\Http\Controllers\Api\V1\ServiceReviewController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth.login');

    /*
     * Slice 0 — Guest authentication (phone + OTP). Separate Sanctum guard
     * (`auth:guest`, provider `guests`) from the staff surface. OTP endpoints
     * are unauthenticated (the code is the credential) and rate limited per
     * Phase 0 §17. Wrong-code / lock-out are 200 outcomes, not errors.
     */
    Route::prefix('guest')->group(function () {
        Route::prefix('auth')->group(function () {
            Route::post('/otp/request', [GuestAuthController::class, 'requestOtp'])
                ->middleware('throttle:guest.otp.request');
            Route::post('/otp/resend', [GuestAuthController::class, 'resendOtp'])
                ->middleware('throttle:guest.otp.request');
            Route::post('/otp/verify', [GuestAuthController::class, 'verifyOtp'])
                ->middleware('throttle:guest.otp.verify');

            Route::middleware('auth:guest')->group(function () {
                Route::get('/me', [GuestAuthController::class, 'me']);
                Route::post('/logout', [GuestAuthController::class, 'logout']);
            });
        });

        Route::middleware('auth:guest')->group(function () {
            Route::match(['put', 'patch'], '/profile', [GuestAuthController::class, 'updateProfile']);
            // PROFILE_Preferences / PROFILE_Privacy.
            Route::patch('/preferences', [GuestAuthController::class, 'updatePreferences'])
                ->middleware('throttle:guest.booking.write');
            Route::post('/privacy/deletion-request', [GuestAuthController::class, 'requestDataDeletion'])
                ->middleware('throttle:guest.booking.write');

            /*
             * Booking funnel — the guest's own reservations + the read view
             * of their deposit payment. Dedicated guest controllers/resources
             * that reuse the shared ReservationService / PaymentWorkflowService
             * (no business logic here). Every row is scoped to the token
             * guest's ownership: a non-owned or missing id is an identical
             * plain 404. Writes are rate limited (guest.booking.write).
             *
             * POST /reservations/{id}/payment/hold holds the hotel's
             * `deposit_percentage` of the stay price; it refuses only while
             * the hotel has no percentage set (see GuestPaymentController).
             */
            Route::get('/reservations', [GuestReservationController::class, 'index']);
            Route::post('/reservations', [GuestReservationController::class, 'store'])
                ->middleware('throttle:guest.booking.write');
            Route::get('/reservations/{reservation}', [GuestReservationController::class, 'show'])
                ->whereNumber('reservation');
            Route::post('/reservations/{reservation}/cancel', [GuestReservationController::class, 'cancel'])
                ->whereNumber('reservation')
                ->middleware('throttle:guest.booking.write');

            // Extend Stay — only while checked_in/in_stay. Re-checks
            // availability for the added nights and prices from
            // room_types.base_price; the incremental amount accrues to the
            // folio and is settled at checkout, same as a service order.
            Route::post('/reservations/{reservation}/extend', [GuestReservationController::class, 'extend'])
                ->whereNumber('reservation')
                ->middleware('throttle:guest.booking.write');

            Route::get('/reservations/{reservation}/payment', [GuestPaymentController::class, 'show'])
                ->whereNumber('reservation');
            Route::post('/reservations/{reservation}/payment/hold', [GuestPaymentController::class, 'hold'])
                ->whereNumber('reservation')
                ->middleware('throttle:guest.booking.write');

            /*
             * Identity verification — reuses IdentityVerificationService
             * unchanged. No guest equivalent of the staff `review` action
             * (manual approve/reject stays staff-only).
             */
            Route::get('/identity/document-types', [GuestIdentityVerificationController::class, 'documentTypes']);
            Route::prefix('/reservations/{reservation}/identity')->whereNumber('reservation')->group(function () {
                Route::post('/documents', [GuestIdentityVerificationController::class, 'documents'])
                    ->middleware('throttle:identity-verification.submit');
                Route::post('/selfie', [GuestIdentityVerificationController::class, 'selfie'])
                    ->middleware('throttle:identity-verification.submit');
                Route::get('/', [GuestIdentityVerificationController::class, 'status']);
            });

            // Check-in — reuses DigitalAccessService::checkIn unchanged; the
            // service independently re-verifies eligibility.
            Route::post('/reservations/{reservation}/check-in', [GuestCheckInController::class, 'store'])
                ->whereNumber('reservation')
                ->middleware('throttle:check-in');

            // Digital access — read-only; revoke stays staff-only.
            Route::get('/reservations/{reservation}/access', [GuestDigitalAccessController::class, 'show'])
                ->whereNumber('reservation');

            // Stay services — the guest's own service orders. Client
            // supplies only service_id/quantity/notes; price/total/status
            // always derived server-side.
            Route::prefix('/reservations/{reservation}/service-orders')->whereNumber('reservation')->group(function () {
                Route::get('/', [GuestServiceOrderController::class, 'index']);
                Route::post('/', [GuestServiceOrderController::class, 'store'])
                    ->middleware('throttle:guest.booking.write');
                Route::get('/{serviceOrder}', [GuestServiceOrderController::class, 'show'])->whereNumber('serviceOrder');
                Route::post('/{serviceOrder}/cancel', [GuestServiceOrderController::class, 'cancel'])
                    ->whereNumber('serviceOrder')
                    ->middleware('throttle:guest.booking.write');

                // Service review — one per fulfilled order. Eligibility
                // (order must be `fulfilled`), ownership, the
                // one-review-per-order rule and moderation are all resolved
                // server-side (mirrors the hotel-level Review route above).
                Route::prefix('/{serviceOrder}/review')->whereNumber('serviceOrder')->group(function () {
                    Route::get('/', [GuestServiceReviewController::class, 'show']);
                    Route::post('/', [GuestServiceReviewController::class, 'store'])
                        ->middleware('throttle:guest.booking.write');
                });
            });

            // Folio — read-only.
            Route::get('/reservations/{reservation}/folio', [GuestFolioController::class, 'show'])
                ->whereNumber('reservation');

            // Checkout — settlement amount always computed server-side from
            // the authoritative folio.
            Route::post('/reservations/{reservation}/checkout', [GuestCheckoutController::class, 'store'])
                ->whereNumber('reservation')
                ->middleware('throttle:checkout.perform');

            // Invoice — read-only.
            Route::get('/reservations/{reservation}/invoice', [GuestInvoiceController::class, 'show'])
                ->whereNumber('reservation');

            // Loyalty — reservation-scoped so hotel scope + guest identity
            // resolve server-side. Ledger-authoritative; no guest-triggerable
            // `earn` (see GuestLoyaltyController).
            Route::prefix('/reservations/{reservation}/loyalty')->whereNumber('reservation')->group(function () {
                Route::get('/', [GuestLoyaltyController::class, 'show']);
                Route::get('/transactions', [GuestLoyaltyController::class, 'transactions']);
                Route::post('/redeem', [GuestLoyaltyController::class, 'redeem'])
                    ->middleware('throttle:guest.booking.write');
            });

            // Notifications — reservation-scoped so the recipient (this
            // reservation's own guest) resolves server-side; the feed shows
            // the `in_app` channel only, never staff-wide notifications.
            Route::prefix('/reservations/{reservation}/notifications')->whereNumber('reservation')->middleware('throttle:notifications.read')->group(function () {
                Route::get('/', [GuestNotificationController::class, 'index']);
                Route::patch('/{notification}/read', [GuestNotificationController::class, 'markRead'])->whereNumber('notification');
                Route::post('/read-all', [GuestNotificationController::class, 'markAllRead']);
            });

            // Loyalty program + balance for the booking summary (pre-booking).
            Route::get('/hotels/{hotel}/loyalty', [GuestLoyaltyController::class, 'program'])->whereNumber('hotel');

            // Favourite hotels — the heart on hotel cards / Hotel Detail.
            // Active hotels only; save/remove are idempotent.
            Route::get('/favorites/hotels', [GuestFavoriteHotelController::class, 'index']);
            Route::put('/favorites/hotels/{hotel}', [GuestFavoriteHotelController::class, 'store'])
                ->whereNumber('hotel')
                ->middleware('throttle:guest.booking.write');
            Route::delete('/favorites/hotels/{hotel}', [GuestFavoriteHotelController::class, 'destroy'])
                ->whereNumber('hotel')
                ->middleware('throttle:guest.booking.write');

            // The guest's whole in-app feed (all reservations) — the app's
            // notifications screen + bell badge (`meta.unread_count`).
            // Recipient-scoped server-side.
            Route::prefix('/notifications')->middleware('throttle:notifications.read')->group(function () {
                Route::get('/', [GuestNotificationController::class, 'feed']);
                Route::patch('/{notification}/read', [GuestNotificationController::class, 'markFeedRead'])->whereNumber('notification');
                Route::post('/read-all', [GuestNotificationController::class, 'markFeedAllRead']);
            });

            // Review — one per completed reservation. Eligibility (the same
            // "completed/stayed" concept loyalty-earn uses), ownership, the
            // one-review-per-stay rule and moderation are all resolved
            // server-side.
            Route::get('/reservations/{reservation}/review', [GuestReviewController::class, 'show'])
                ->whereNumber('reservation');
            Route::post('/reservations/{reservation}/review', [GuestReviewController::class, 'store'])
                ->whereNumber('reservation')
                ->middleware('throttle:guest.booking.write');

            // Problem reports — a guest reports an in-stay issue (category +
            // urgency + optional note); hotel/status are server-derived.
            // Many per reservation, unlike Review (mobile/Design/13 · Report
            // a problem.png).
            Route::prefix('/reservations/{reservation}/problems')->whereNumber('reservation')->group(function () {
                Route::get('/', [GuestProblemReportController::class, 'index']);
                Route::post('/', [GuestProblemReportController::class, 'store'])
                    ->middleware('throttle:guest.booking.write');
                Route::get('/{problem}', [GuestProblemReportController::class, 'show'])->whereNumber('problem');
            });
        });

        // Guest App branding + entry content (splash logo/wordmark,
        // onboarding photo + copy) — anonymous, shown before sign-in;
        // managed from the dashboard via /app-content below.
        Route::get('/app-content', [GuestAppContentReadController::class, 'show']);

        /*
         * Slice 1 — anonymous discovery. Active hotels / active room types
         * only; no caller identity, so no hotel scope. Availability reuses
         * ReservationService's overlap math as a non-locking preview.
         */
        Route::get('/hotels', [GuestDiscoveryController::class, 'hotels']);
        Route::get('/hotels/cities', [GuestDiscoveryController::class, 'cities']);
        Route::get('/hotels/{hotel}', [GuestDiscoveryController::class, 'show'])->whereNumber('hotel');
        Route::get('/hotels/{hotel}/availability', [GuestDiscoveryController::class, 'availability'])->whereNumber('hotel');

        // Hotel service catalog — anonymous, active-hotel/active-catalog
        // only, mirrors the discovery routes above.
        Route::get('/hotels/{hotel}/service-categories', [GuestServiceCatalogController::class, 'categories'])->whereNumber('hotel');
        Route::get('/hotels/{hotel}/services', [GuestServiceCatalogController::class, 'services'])->whereNumber('hotel');

        // Published reviews for a hotel — anonymous, matches the "browse
        // without login" rule; pending/rejected reviews are never returned.
        Route::get('/hotels/{hotel}/reviews', [GuestReviewController::class, 'forHotel'])->whereNumber('hotel');
        // The hotel's active, dashboard-managed review categories — the guest
        // review form is built dynamically from this list.
        Route::get('/hotels/{hotel}/review-categories', [GuestReviewController::class, 'categories'])->whereNumber('hotel');
    });

    // Provider webhook — machine-to-machine, unauthenticated: the HMAC
    // signature is the credential (Phase 5E). Rate limited per Phase 0 §17,
    // keyed by IP and set high for legitimate provider retries (Phase 5F).
    Route::post('/payments/webhooks/{provider}', [PaymentWebhookController::class, 'handle'])
        ->middleware('throttle:payments.webhook');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        Route::get('/roles', [RoleController::class, 'index']);
        Route::post('/roles', [RoleController::class, 'store']);
        Route::get('/roles/{role}', [RoleController::class, 'show']);
        Route::match(['put', 'patch'], '/roles/{role}', [RoleController::class, 'update']);
        Route::delete('/roles/{role}', [RoleController::class, 'destroy']);

        Route::get('/permissions', [PermissionController::class, 'index']);

        // Guest App branding + entry content editor (`app-content.manage`).
        // App-wide, not hotel-scoped. The guest app reads it anonymously
        // via /guest/app-content.
        Route::get('/app-content', [GuestAppContentController::class, 'show']);
        Route::patch('/app-content', [GuestAppContentController::class, 'update']);
        Route::post('/app-content/images', [GuestAppContentController::class, 'uploadImage'])
            ->middleware('throttle:hotel-media.upload');
        Route::delete('/app-content/images/{slot}', [GuestAppContentController::class, 'removeImage']);

        Route::get('/hotel-groups', [HotelGroupController::class, 'index']);
        Route::post('/hotel-groups', [HotelGroupController::class, 'store']);
        Route::get('/hotel-groups/{hotel_group}', [HotelGroupController::class, 'show']);
        Route::put('/hotel-groups/{hotel_group}', [HotelGroupController::class, 'update']);

        // Phase 10 — Loyalty rule configuration (Phase 0 §7/§13). Group
        // Owner only; the rule is created inactive/unconfigured on first read.
        Route::get('/hotel-groups/{hotel_group}/loyalty-rule', [LoyaltyRuleController::class, 'show']);
        Route::match(['put', 'patch'], '/hotel-groups/{hotel_group}/loyalty-rule', [LoyaltyRuleController::class, 'update']);

        Route::get('/hotels', [HotelController::class, 'index']);
        Route::post('/hotels', [HotelController::class, 'store']);
        Route::get('/hotels/{hotel}', [HotelController::class, 'show']);
        Route::match(['put', 'patch'], '/hotels/{hotel}', [HotelController::class, 'update']);
        Route::delete('/hotels/{hotel}', [HotelController::class, 'destroy']);

        /*
         * Facility catalog — global reference data (NOT hotel-scoped),
         * mirrors the Country/City pattern. facilities.view for read,
         * facilities.manage for every write. `GET /facilities?all=1`
         * returns every active facility unpaginated for the Hotel
         * create/edit picker.
         */
        Route::get('/facilities', [FacilityController::class, 'index']);
        Route::post('/facilities', [FacilityController::class, 'store']);
        Route::get('/facilities/{facility}', [FacilityController::class, 'show'])->whereNumber('facility');
        Route::match(['put', 'patch'], '/facilities/{facility}', [FacilityController::class, 'update'])->whereNumber('facility');
        Route::delete('/facilities/{facility}', [FacilityController::class, 'destroy'])->whereNumber('facility');
        Route::patch('/facilities/{facility}/activate', [FacilityController::class, 'activate'])->whereNumber('facility');
        Route::patch('/facilities/{facility}/deactivate', [FacilityController::class, 'deactivate'])->whereNumber('facility');

        /*
         * Country + City master data (global reference data — NOT
         * hotel-scoped). Permission-driven: locations.view for read,
         * locations.manage for every write. `/countries/{country}/cities`
         * powers the dependent Country -> City select in the dashboard.
         */
        Route::get('/countries', [CountryController::class, 'index']);
        Route::post('/countries', [CountryController::class, 'store']);
        Route::get('/countries/{country}', [CountryController::class, 'show'])->whereNumber('country');
        Route::match(['put', 'patch'], '/countries/{country}', [CountryController::class, 'update'])->whereNumber('country');
        Route::delete('/countries/{country}', [CountryController::class, 'destroy'])->whereNumber('country');
        Route::patch('/countries/{country}/activate', [CountryController::class, 'activate'])->whereNumber('country');
        Route::patch('/countries/{country}/deactivate', [CountryController::class, 'deactivate'])->whereNumber('country');
        Route::get('/countries/{country}/cities', [CityController::class, 'forCountry'])->whereNumber('country');

        Route::get('/cities', [CityController::class, 'index']);
        Route::post('/cities', [CityController::class, 'store']);
        Route::get('/cities/{city}', [CityController::class, 'show'])->whereNumber('city');
        Route::match(['put', 'patch'], '/cities/{city}', [CityController::class, 'update'])->whereNumber('city');
        Route::delete('/cities/{city}', [CityController::class, 'destroy'])->whereNumber('city');
        Route::patch('/cities/{city}/activate', [CityController::class, 'activate'])->whereNumber('city');
        Route::patch('/cities/{city}/deactivate', [CityController::class, 'deactivate'])->whereNumber('city');

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);

        // Guests — staff-facing directory (read-only; a Guest's own data is
        // only ever mutated through the guest app's own profile flow). Not
        // hotel-scoped, matching Users; the reservations sub-list is still
        // filtered through the caller's own hotel access.
        // Staff reports — aggregate reads across the caller's accessible
        // hotels, never a single owning Model, so not nested under /hotels.
        Route::get('/reports/occupancy', [ReportController::class, 'occupancy']);
        Route::get('/reports/revenue', [ReportController::class, 'revenue']);
        Route::get('/reports/hotel-comparison', [ReportController::class, 'hotelComparison']);
        Route::get('/reports/reservations', [ReportController::class, 'reservations']);
        Route::get('/reports/payments', [ReportController::class, 'payments']);
        Route::get('/reports/services', [ReportController::class, 'services']);
        Route::get('/reports/loyalty', [ReportController::class, 'loyalty']);
        Route::get('/reports/reviews', [ReportController::class, 'reviews']);

        // Audit trail read — group-wide (Group Owner only); the hotel-scoped
        // variant is registered in the /hotels/{hotel} group below.
        Route::get('/audit-log', [AuditLogController::class, 'global']);

        Route::get('/guests', [GuestController::class, 'index']);
        Route::post('/guests', [GuestController::class, 'store']);
        Route::get('/guests/{guest}', [GuestController::class, 'show']);
        Route::get('/guests/{guest}/reservations', [GuestController::class, 'reservations']);

        // Guest-level loyalty dashboard — read-only, group-wide (not
        // hotel-scoped, like the rest of the Guest directory). Earn/redeem
        // stay reservation-scoped under /reservations/{reservation}/loyalty.
        Route::get('/guests/{guest}/loyalty', [LoyaltyController::class, 'guestAccount']);
        Route::get('/guests/{guest}/loyalty/transactions', [LoyaltyController::class, 'guestTransactions']);

        Route::get('/reservations', [ReservationController::class, 'index']);
        Route::post('/reservations', [ReservationController::class, 'store']);
        Route::get('/reservations/{reservation}', [ReservationController::class, 'show']);
        Route::post('/reservations/{reservation}/transition', [ReservationController::class, 'transition']);
        Route::get('/reservations/{reservation}/assignable-rooms', [ReservationController::class, 'assignableRooms']);
        Route::post('/reservations/{reservation}/room', [ReservationController::class, 'assignRoom']);
        Route::post('/reservations/{reservation}/payment/hold', [PaymentController::class, 'hold'])
            ->middleware('throttle:payments.hold');

        // Extend Stay — dashboard equivalent of the guest
        // /guest/reservations/{reservation}/extend endpoint below. Same
        // ReservationExtensionService, same eligibility (checked_in/in_stay)
        // and availability/pricing rules; `Idempotency-Key` header. No
        // throttle — matches store()/transition() above, neither of which is
        // rate limited on the staff surface.
        Route::post('/reservations/{reservation}/extend', [ReservationController::class, 'extend']);

        // Phase 8 — Stay Services + Folio (Phase 0 §16). {reservation} is an
        // int id resolved through ReservationService (not route-model
        // binding), so a cross-hotel or missing id is an identical plain
        // 404. Service orders accrue folio charges; the folio is read-only.
        Route::prefix('/reservations/{reservation}')->group(function () {
            Route::get('/service-orders', [ServiceOrderController::class, 'index']);
            Route::post('/service-orders', [ServiceOrderController::class, 'store']);
            Route::get('/service-orders/{serviceOrder}', [ServiceOrderController::class, 'show']);
            Route::post('/service-orders/{serviceOrder}/transition', [ServiceOrderController::class, 'transition']);

            Route::get('/folio', [FolioController::class, 'show']);

            // Phase 9 — Checkout + Final Settlement + Invoice (Phase 0 §12/§16).
            // {reservation} is an int id resolved through ReservationService,
            // so a cross-hotel or missing id is an identical plain 404.
            // Checkout is financially sensitive — rate limited like the
            // payment endpoints (Phase 0 §17). `Idempotency-Key` header.
            Route::post('/checkout', [CheckoutController::class, 'store'])
                ->middleware('throttle:checkout.perform');

            Route::get('/invoice', [InvoiceController::class, 'show']);

            // Phase 10 — Loyalty (Phase 0 §13/§16). Reservation-scoped so
            // hotel scope + guest identity are resolved server-side (no guest
            // auth in the MVP). `earn` accrues for a completed booking;
            // `redeem` spends against an eligible (non-terminal) booking.
            Route::get('/loyalty', [LoyaltyController::class, 'show']);
            Route::get('/loyalty/transactions', [LoyaltyController::class, 'transactions']);
            Route::post('/loyalty/earn', [LoyaltyController::class, 'earn']);
            Route::post('/loyalty/redeem', [LoyaltyController::class, 'redeem']);

            // Phase 11 — Notifications (Phase 0 §4/§15/§16). Reservation-scoped
            // so hotel scope + the guest recipient are resolved server-side
            // (no guest auth in the MVP). Read-only feed of the `in_app`
            // channel plus its unread markers; notifications are produced by
            // the approved Reservation lifecycle, never created over HTTP.
            // Rate limited per Phase 0 §17.
            Route::middleware('throttle:notifications.read')->group(function () {
                Route::get('/notifications', [NotificationController::class, 'index']);
                Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
                Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
            });
        });

        // Phase 6 — Identity Verification (Phase 0 §16). {reservation} is an
        // int id resolved through ReservationService (not route-model
        // binding), so a cross-hotel or missing id is an identical plain
        // 404. The upload endpoints are rate limited per Phase 0 §17.
        Route::get('/identity-verification/document-types', [IdentityVerificationController::class, 'documentTypes']);
        Route::prefix('/identity-verification/{reservation}')->group(function () {
            Route::post('/documents', [IdentityVerificationController::class, 'documents'])
                ->middleware('throttle:identity-verification.submit');
            Route::post('/selfie', [IdentityVerificationController::class, 'selfie'])
                ->middleware('throttle:identity-verification.submit');
            Route::get('/status', [IdentityVerificationController::class, 'status']);
            Route::post('/review', [IdentityVerificationController::class, 'review']);
        });

        // Phase 7 — Check-in + Digital Access (Phase 0 §16). {reservation} is
        // an int id resolved through ReservationService (not route-model
        // binding), so a cross-hotel or missing id is an identical plain
        // 404. Check-in issues the digital access credential and, on success,
        // drives VERIFIED -> CHECKED_IN via ReservationService.
        Route::post('/check-in/{reservation}', [CheckInController::class, 'store'])
            ->middleware('throttle:check-in');

        Route::get('/access/{reservation}', [DigitalAccessController::class, 'show']);
        Route::post('/access/{reservation}/revoke', [DigitalAccessController::class, 'revoke'])
            ->middleware('throttle:digital-access.revoke');

        // Reviews — staff moderation decision. Listing a hotel's reviews
        // (every moderation state) is registered in the /hotels/{hotel}
        // group below, alongside the rest of the hotel-scoped staff reads.
        Route::post('/reviews/{review}/moderate', [ReviewController::class, 'moderate'])
            ->whereNumber('review');

        // Service reviews — staff moderation decision, same reviews.*
        // permissions as the hotel-level Review above (see
        // ServiceReviewPolicy). Listing a hotel's service reviews is
        // registered in the /hotels/{hotel} group below.
        Route::post('/service-reviews/{serviceReview}/moderate', [ServiceReviewController::class, 'moderate'])
            ->whereNumber('serviceReview');

        // Problem reports — staff triage decision. Listing a hotel's reports
        // is registered in the /hotels/{hotel} group below, alongside the
        // rest of the hotel-scoped staff reads.
        Route::patch('/problems/{problem}/status', [ProblemReportController::class, 'transitionStatus'])
            ->whereNumber('problem');

        Route::prefix('/hotels/{hotel}')->group(function () {
            // Hotel media (logo / cover / gallery). Staff-only —
            // `hotels.manage` + hotel scope (HotelPolicy::manageMedia). The
            // guest app never calls these; it reads the resolved URLs off
            // the public hotel resource. `reorder` is declared before the
            // `{media}` param route so it is not shadowed.
            Route::patch('/media/reorder', [HotelMediaController::class, 'reorder']);
            Route::post('/media', [HotelMediaController::class, 'store'])
                ->middleware('throttle:hotel-media.upload');
            Route::delete('/media/{media}', [HotelMediaController::class, 'destroy'])
                ->whereNumber('media');

            Route::get('/room-types', [RoomTypeController::class, 'index']);
            Route::post('/room-types', [RoomTypeController::class, 'store']);
            Route::get('/room-types/{roomType}', [RoomTypeController::class, 'show']);
            Route::match(['put', 'patch'], '/room-types/{roomType}', [RoomTypeController::class, 'update']);
            Route::patch('/room-types/{roomType}/activate', [RoomTypeController::class, 'activate']);
            Route::patch('/room-types/{roomType}/deactivate', [RoomTypeController::class, 'deactivate']);

            // Room Type media (gallery). Staff-only — `inventory.manage` +
            // hotel scope (RoomTypePolicy::manageMedia). `reorder` is
            // declared before the `{media}` param route so it is not
            // shadowed.
            Route::patch('/room-types/{roomType}/media/reorder', [RoomTypeMediaController::class, 'reorder']);
            Route::post('/room-types/{roomType}/media', [RoomTypeMediaController::class, 'store'])
                ->middleware('throttle:room-media.upload');
            Route::delete('/room-types/{roomType}/media/{media}', [RoomTypeMediaController::class, 'destroy'])
                ->whereNumber('media');

            Route::get('/rooms', [RoomController::class, 'index']);
            Route::post('/rooms', [RoomController::class, 'store']);
            Route::get('/rooms/{room}', [RoomController::class, 'show']);
            Route::match(['put', 'patch'], '/rooms/{room}', [RoomController::class, 'update']);
            Route::patch('/rooms/{room}/status', [RoomController::class, 'updateStatus']);

            // Room media (gallery) — a per-physical-room override on top of
            // the room type's shared gallery. Staff-only — `inventory.manage`
            // + hotel scope (RoomPolicy::manageMedia).
            Route::patch('/rooms/{room}/media/reorder', [RoomMediaController::class, 'reorder']);
            Route::post('/rooms/{room}/media', [RoomMediaController::class, 'store'])
                ->middleware('throttle:room-media.upload');
            Route::delete('/rooms/{room}/media/{media}', [RoomMediaController::class, 'destroy'])
                ->whereNumber('media');

            // Phase 8 — hotel service catalog (Phase 0 §16). Categories are
            // an optional grouping; services carry the price. No delete —
            // deactivation preserves historical references.
            Route::get('/service-categories', [ServiceCategoryController::class, 'index']);
            Route::post('/service-categories', [ServiceCategoryController::class, 'store']);
            Route::match(['put', 'patch'], '/service-categories/{serviceCategory}', [ServiceCategoryController::class, 'update']);
            Route::patch('/service-categories/{serviceCategory}/activate', [ServiceCategoryController::class, 'activate']);
            Route::patch('/service-categories/{serviceCategory}/deactivate', [ServiceCategoryController::class, 'deactivate']);

            Route::get('/services', [ServiceController::class, 'index']);
            Route::post('/services', [ServiceController::class, 'store']);
            Route::get('/services/{service}', [ServiceController::class, 'show']);
            Route::match(['put', 'patch'], '/services/{service}', [ServiceController::class, 'update']);
            Route::patch('/services/{service}/activate', [ServiceController::class, 'activate']);
            Route::patch('/services/{service}/deactivate', [ServiceController::class, 'deactivate']);

            // Reviews — every moderation state (pending/published/rejected);
            // the guest-facing published-only listing is under /guest above.
            Route::get('/reviews', [ReviewController::class, 'index']);
            // Rating analytics (totals, overall + per-category averages),
            // built live from the hotel's dynamic review categories.
            Route::get('/reviews/analytics', [ReviewController::class, 'analytics']);

            // Dynamic review categories (the criteria guests rate a stay on).
            // Deactivation hides one from new reviews; delete only while no
            // guest has rated it yet.
            Route::get('/review-categories', [ReviewCategoryController::class, 'index']);
            Route::post('/review-categories', [ReviewCategoryController::class, 'store']);
            Route::put('/review-categories/reorder', [ReviewCategoryController::class, 'reorder']);
            Route::match(['put', 'patch'], '/review-categories/{reviewCategory}', [ReviewCategoryController::class, 'update'])
                ->whereNumber('reviewCategory');
            Route::patch('/review-categories/{reviewCategory}/activate', [ReviewCategoryController::class, 'activate'])
                ->whereNumber('reviewCategory');
            Route::patch('/review-categories/{reviewCategory}/deactivate', [ReviewCategoryController::class, 'deactivate'])
                ->whereNumber('reviewCategory');
            Route::delete('/review-categories/{reviewCategory}', [ReviewCategoryController::class, 'destroy'])
                ->whereNumber('reviewCategory');

            // Service reviews — every moderation state, optionally filtered
            // by ?service_id/&status; the guest-facing
            // one-per-service-order read is under /guest above.
            Route::get('/service-reviews', [ServiceReviewController::class, 'index']);

            // Problem reports — every status; the guest-facing
            // reservation-scoped listing is under /guest above.
            Route::get('/problems', [ProblemReportController::class, 'index']);

            // Staff financial ledgers — hotel-wide reads over the same
            // reservation-scoped domains the workspace already uses.
            Route::get('/payments', [PaymentController::class, 'index']);
            Route::get('/invoices', [InvoiceController::class, 'index']);
            Route::get('/settlements', [CheckoutController::class, 'index']);

            // Front-desk lists — hotel-wide reads over Reservation; the
            // per-reservation check-in / access flow lives in the workspace.
            Route::get('/arrivals', [ReservationController::class, 'arrivals']);
            Route::get('/departures', [ReservationController::class, 'departures']);
            Route::get('/in-house', [ReservationController::class, 'inHouse']);

            // Staff-wide notification feed — hotel-wide read over the same
            // reservation-scoped in_app channel the workspace already uses.
            Route::get('/notifications', [NotificationController::class, 'forHotel']);

            // Audit trail — hotel-scoped read.
            Route::get('/audit-log', [AuditLogController::class, 'forHotel']);

            // Standalone folio ledger — every reservation with a live
            // folio, same FolioService the reservation-scoped read uses.
            Route::get('/folios', [FolioController::class, 'index']);
        });
    });
});
