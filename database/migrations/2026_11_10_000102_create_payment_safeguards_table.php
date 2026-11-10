<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalogs', function (Blueprint $table): void {
            $table->boolean('safeguard_enabled')->default(false)->after('rate_source');
        });

        Schema::create('payment_safeguards', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();

            $table->string('destination', 30);
            $table->string('reference', 120)->nullable();
            $table->string('proof_path')->nullable();
            $table->string('proof_mime', 100)->nullable();
            $table->string('note', 500)->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['organization_id', 'payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_safeguards');

        Schema::table('catalogs', function (Blueprint $table): void {
            $table->dropColumn('safeguard_enabled');
        });
    }
};
