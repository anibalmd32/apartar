<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_monthly_aggregates', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event_name', 40);
            $table->char('year_month', 7);
            $table->unsignedBigInteger('count');

            $table->timestamps();

            $table->unique(['organization_id', 'event_name', 'year_month'], 'telemetry_monthly_aggregates_unique');
            $table->index(['organization_id', 'year_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_monthly_aggregates');
    }
};
