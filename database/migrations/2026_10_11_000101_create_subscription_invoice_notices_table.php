<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_invoice_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['organization_id', 'subscription_invoice_id', 'type'], 'subscription_invoice_notices_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoice_notices');
    }
};
