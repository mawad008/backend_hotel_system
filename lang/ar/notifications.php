<?php

/*
 * Phase 11 — قوالب رسائل الإشعارات. مدخل واحد لكل نوع إشعار، وكل منها بيان
 * وقائعي محدد لحالة الحجز التي تم بلوغها للتو — بلا وعود مصطنعة ولا بيانات
 * إضافية عدا مرجع الحجز. :reference هو العنصر النائب الوحيد.
 */

return [

    'reservation_deposit_held' => [
        'subject' => 'تم تأكيد مبلغ التأمين',
        'body' => 'تم تأكيد تجميد مبلغ التأمين للحجز :reference.',
    ],

    'identity_verified' => [
        'subject' => 'تم التحقق من الهوية',
        'body' => 'اكتمل التحقق من الهوية للحجز :reference.',
    ],

    'reservation_checked_in' => [
        'subject' => 'اكتمل تسجيل الوصول',
        'body' => 'اكتمل تسجيل الوصول للحجز :reference وتم إصدار تصريح الدخول الرقمي.',
    ],

    'reservation_invoiced' => [
        'subject' => 'الفاتورة متاحة',
        'body' => 'فاتورة الحجز :reference متاحة الآن.',
    ],

    'reservation_cancelled' => [
        'subject' => 'تم إلغاء الحجز',
        'body' => 'تم إلغاء الحجز :reference.',
    ],

];
