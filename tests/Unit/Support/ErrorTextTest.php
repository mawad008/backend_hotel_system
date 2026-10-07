<?php

namespace Tests\Unit\Support;

use App\Domain\DigitalAccess\Exceptions\CheckInEligibilityException;
use App\Domain\Loyalty\Exceptions\LoyaltyNotAllowedException;
use App\Domain\Reservation\Exceptions\InvalidReservationStatusTransitionException;
use App\Domain\Reservation\Exceptions\ReservationNotAvailableException;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class ErrorTextTest extends TestCase
{
    public function test_english_messages_are_unchanged(): void
    {
        App::setLocale('en');

        $this->assertSame(
            "Cannot transition a reservation from 'pending' to 'checked_out'.",
            (new InvalidReservationStatusTransitionException('pending', 'checked_out'))->getMessage(),
        );
        $this->assertSame(
            'This loyalty operation is not allowed (reservation_not_completed:in_stay).',
            LoyaltyNotAllowedException::reservationNotCompleted('in_stay')->getMessage(),
        );
    }

    public function test_arabic_messages_translate_the_text_and_the_embedded_codes(): void
    {
        App::setLocale('ar');

        $this->assertSame('التواريخ المطلوبة غير متاحة.', (new ReservationNotAvailableException)->getMessage());
        $this->assertSame(
            'لا يمكن تغيير حالة الحجز من «قيد الانتظار» إلى «تمت المغادرة».',
            (new InvalidReservationStatusTransitionException('pending', 'checked_out'))->getMessage(),
        );
        $this->assertSame(
            'تسجيل الدخول غير متاح حاليًا: لم يتم التحقق من الهوية.',
            CheckInEligibilityException::identityNotVerified()->getMessage(),
        );
        $this->assertSame(
            'عملية الولاء غير مسموحة: الحجز في حالة «أثناء الإقامة» ولم يكتمل بعد.',
            LoyaltyNotAllowedException::reservationNotCompleted('in_stay')->getMessage(),
        );
    }

    public function test_every_arabic_message_key_exists_in_english(): void
    {
        $en = require lang_path('en/errors.php');
        $ar = require lang_path('ar/errors.php');

        $messages = array_diff_key($ar, array_flip(['status', 'reason', 'action']));
        $this->assertSame([], array_keys(array_diff_key($messages, $en)));
        $this->assertSame([], array_keys(array_diff_key($en, $messages)));
    }
}
