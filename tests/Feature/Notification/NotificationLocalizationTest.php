<?php

namespace Tests\Feature\Notification;

use App\Domain\HotelGroup\Models\Hotel;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Services\NotificationRecipient;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Reservation\Models\Reservation;
use Tests\TestCase;

class NotificationLocalizationTest extends TestCase
{
    private function reservation(): Reservation
    {
        return Reservation::factory()->create([
            'hotel_id' => Hotel::factory()->create()->id,
            'status' => Reservation::STATUS_DEPOSIT_HELD,
        ]);
    }

    private function dispatch(Reservation $reservation, ?string $locale): void
    {
        app(NotificationService::class)->dispatchForReservation(
            type: NotificationType::ReservationDepositHeld,
            reservation: $reservation,
            recipient: NotificationRecipient::fromGuest($reservation->guest),
            eventKey: 'reservation:'.$reservation->id.':deposit_held',
            locale: $locale,
        );
    }

    public function test_english_is_the_default_render(): void
    {
        $reservation = $this->reservation();
        $this->dispatch($reservation, null);

        $row = Notification::query()->where('reservation_id', $reservation->id)->first();
        $this->assertSame('en', $row->locale);
        $this->assertSame('Deposit confirmed', $row->subject);
        $this->assertSame(
            "The deposit hold for reservation #{$reservation->id} has been confirmed.",
            $row->body,
        );
    }

    public function test_arabic_render_is_snapshotted_on_the_row(): void
    {
        $reservation = $this->reservation();
        $this->dispatch($reservation, 'ar');

        $row = Notification::query()->where('reservation_id', $reservation->id)->first();
        $this->assertSame('ar', $row->locale);
        $this->assertSame('تم تأكيد مبلغ التأمين', $row->subject);
        $this->assertStringContainsString('#'.$reservation->id, $row->body);
    }

    public function test_an_unsupported_locale_falls_back_to_the_default(): void
    {
        $reservation = $this->reservation();
        $this->dispatch($reservation, 'fr');

        $row = Notification::query()->where('reservation_id', $reservation->id)->first();
        $this->assertSame('en', $row->locale);
        $this->assertSame('Deposit confirmed', $row->subject);
    }
}
