<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('invitation_token_hash', 64)->nullable()->after('invited_at');

            $table->timestamp('invitation_expires_at')->nullable()->after('invitation_token_hash');

            $table->index(['organization_id', 'invitation_token_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'invitation_token_hash']);
            $table->dropColumn(['invitation_token_hash', 'invitation_expires_at']);
        });
    }
};
