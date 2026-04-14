<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('time_slot_id')->constrained()->cascadeOnDelete();

            $table->enum('status', [
                'pending',
                'confirmed',
                'cancelled',
                'checked_in',
                'partial_attendance',
                'checked_out',
                'no_show',
                'expired',
                'rescheduled',
            ])->default('pending');

            $table->timestampTz('requested_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('expires_at')->nullable(); // pending + 30 min
            $table->timestampTz('cancelled_at')->nullable();

            $table->foreignId('cancellation_reason_id')->nullable()
                ->constrained('data_dictionary_values')->nullOnDelete();
            $table->enum('cancellation_consequence', ['none', 'fee', 'points'])->default('none');
            $table->decimal('cancellation_consequence_amount', 10, 2)->default(0);

            $table->timestampTz('checked_in_at')->nullable();
            $table->timestampTz('checked_out_at')->nullable();

            // Reschedule chain
            $table->foreignId('rescheduled_from_id')->nullable()
                ->constrained('reservations')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['time_slot_id', 'status']);
            $table->index(['status', 'expires_at']); // for expiry job
        });

        Schema::create('reservation_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type', 10)->default('user'); // user | system
            $table->text('reason')->nullable();
            $table->timestampTz('occurred_at');

            $table->index('reservation_id');
        });

        Schema::create('no_show_breaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('breach_type', 30); // no_show | late_cancel
            $table->timestampTz('occurred_at');

            $table->index(['user_id', 'occurred_at']); // for rolling-window count
        });

        Schema::create('booking_freezes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->text('reason')->nullable();
            $table->unsignedSmallInteger('trigger_breach_count')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestampTz('lifted_at')->nullable();

            $table->index(['user_id', 'ends_at']);
        });

        Schema::create('points_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('amount'); // positive = credit, negative = debit
            $table->string('reason', 255)->nullable();
            $table->string('reference_type', 80)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->integer('balance_after');
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('points_ledger');
        Schema::dropIfExists('booking_freezes');
        Schema::dropIfExists('no_show_breaches');
        Schema::dropIfExists('reservation_status_history');
        Schema::dropIfExists('reservations');
    }
};
