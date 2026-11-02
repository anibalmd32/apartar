<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHECK = 'payments_surplus_destination_complete';

    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('surplus_destination_type', 20)->nullable()->after('unallocated_cents');
            $table->foreignId('surplus_user_id')->nullable()->after('surplus_destination_type')->constrained('users')->nullOnDelete();
            $table->string('surplus_team_member_name', 255)->nullable()->after('surplus_user_id');
            $table->string('surplus_note', 500)->nullable()->after('surplus_team_member_name');
            $table->foreignId('surplus_assigned_by')->nullable()->after('surplus_note')->constrained('users')->nullOnDelete();
            $table->timestamp('surplus_assigned_at')->nullable()->after('surplus_assigned_by');
        });

        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT '.self::CHECK.' CHECK ('
            .'(surplus_destination_type IS NULL AND surplus_team_member_name IS NULL AND surplus_note IS NULL) '
            .'OR (surplus_destination_type IS NOT NULL AND surplus_assigned_at IS NOT NULL))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CHECK '.self::CHECK);

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('surplus_user_id');
            $table->dropConstrainedForeignId('surplus_assigned_by');
            $table->dropColumn(['surplus_destination_type', 'surplus_team_member_name', 'surplus_note', 'surplus_assigned_at']);
        });
    }
};
