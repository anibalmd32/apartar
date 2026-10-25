<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('layaways', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->after('code');
        });

        DB::table('layaways')->whereNull('idempotency_key')->orderBy('id')->each(function (object $row): void {
            DB::table('layaways')->where('id', $row->id)->update(['idempotency_key' => (string) Str::uuid()]);
        });

        Schema::table('layaways', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable(false)->change();

            $table->unique(['organization_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('layaways', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
