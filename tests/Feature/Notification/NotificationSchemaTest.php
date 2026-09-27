<?php

namespace Tests\Feature\Notification;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationSchemaTest extends TestCase
{
    public function test_the_table_exists_with_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('notification_events'));

        $this->assertTrue(Schema::hasColumns('notification_events', [
            'id', 'reservation_id', 'hotel_id', 'recipient_type', 'recipient_id',
            'type', 'channel', 'status', 'locale', 'subject', 'body',
            'provider', 'provider_reference', 'provider_code', 'failure_reason',
            'idempotency_key', 'context', 'sent_at', 'failed_at', 'read_at',
            'created_at', 'updated_at',
        ]));
    }

    public function test_idempotency_key_is_unique(): void
    {
        $indexes = collect(DB::select('SHOW INDEXES FROM notification_events'))
            ->where('Key_name', 'notification_events_idempotency_key_unique');

        $this->assertTrue($indexes->isNotEmpty());
        $this->assertSame(0, (int) $indexes->first()->Non_unique);
    }

    public function test_the_expected_secondary_indexes_exist(): void
    {
        $names = collect(DB::select('SHOW INDEXES FROM notification_events'))
            ->pluck('Key_name')->unique()->values()->all();

        foreach ([
            'notification_events_reservation_channel_index',
            'notification_events_reservation_unread_index',
            'notification_events_recipient_index',
            'notification_events_hotel_status_index',
            'notification_events_status_index',
        ] as $index) {
            $this->assertContains($index, $names);
        }
    }

    public function test_foreign_keys_restrict_deletes(): void
    {
        $fks = collect(DB::select("
            SELECT k.COLUMN_NAME, r.DELETE_RULE
            FROM information_schema.REFERENTIAL_CONSTRAINTS r
            JOIN information_schema.KEY_COLUMN_USAGE k ON k.CONSTRAINT_NAME = r.CONSTRAINT_NAME
            WHERE r.CONSTRAINT_SCHEMA = DATABASE() AND r.TABLE_NAME = 'notification_events'
        "))->keyBy('COLUMN_NAME');

        $this->assertSame('RESTRICT', $fks['reservation_id']->DELETE_RULE);
        $this->assertSame('RESTRICT', $fks['hotel_id']->DELETE_RULE);
    }

    public function test_status_and_channel_enums_only_accept_the_approved_values(): void
    {
        $columns = collect(DB::select('SHOW COLUMNS FROM notification_events'))->keyBy('Field');

        $this->assertSame(
            "enum('pending','sending','sent','failed')",
            $columns['status']->Type,
        );
        $this->assertSame(
            "enum('in_app','email','sms')",
            $columns['channel']->Type,
        );
        $this->assertSame(
            "enum('guest','staff_user')",
            $columns['recipient_type']->Type,
        );
        $this->assertStringContainsString('reservation_deposit_held', $columns['type']->Type);
        $this->assertStringContainsString('reservation_cancelled', $columns['type']->Type);
    }

    public function test_nullability_matches_the_design(): void
    {
        $columns = collect(DB::select('SHOW COLUMNS FROM notification_events'))->keyBy('Field');

        // Delivery bookkeeping / optional context is nullable.
        foreach (['reservation_id', 'hotel_id', 'provider_reference', 'provider_code', 'failure_reason', 'context', 'sent_at', 'failed_at', 'read_at'] as $nullable) {
            $this->assertSame('YES', $columns[$nullable]->Null, "{$nullable} should be nullable");
        }

        // The identity / routing / rendered content is required.
        foreach (['recipient_type', 'recipient_id', 'type', 'channel', 'status', 'locale', 'subject', 'body', 'provider', 'idempotency_key'] as $required) {
            $this->assertSame('NO', $columns[$required]->Null, "{$required} should be NOT NULL");
        }
    }
}
