<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CARRIERS = ['Digitel' => 'digitel', 'Movistar' => 'movistar', 'Movilnet' => 'movilnet'];

    public function up(): void
    {
        foreach (DB::table('banks')->pluck('code') as $code) {
            if (is_file(public_path("images/banks/icons/{$code}.svg"))) {
                DB::table('banks')->where('code', $code)->update(['logo_path' => "images/banks/icons/{$code}.svg"]);
            }
        }

        foreach (self::CARRIERS as $carrier => $slug) {
            DB::table('mobile_prefixes')->where('carrier', $carrier)->update(['logo_path' => "images/carriers/icons/{$slug}.svg"]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('banks')->pluck('code') as $code) {
            $legacy = "images/banks/{$code}.png";

            DB::table('banks')->where('code', $code)->update(['logo_path' => is_file(public_path($legacy)) ? $legacy : null]);
        }

        DB::table('mobile_prefixes')->where('carrier', 'Digitel')->update(['logo_path' => 'images/carriers/digitel.svg']);
        DB::table('mobile_prefixes')->where('carrier', 'Movistar')->update(['logo_path' => 'images/carriers/movistar.png']);
        DB::table('mobile_prefixes')->where('carrier', 'Movilnet')->update(['logo_path' => 'images/carriers/movilnet.png']);
    }
};
