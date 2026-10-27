<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<array{string, string, bool}>
     */
    private const BANKS = [
        ['0156', '100% Banco', true],
        ['0196', 'ABN AMRO Bank', true],
        ['0172', 'Bancamiga', true],
        ['0171', 'Banco Activo', true],
        ['0166', 'Banco Agrícola', true],
        ['0175', 'Banco Bicentenario', true],
        ['0128', 'Banco Caroní', true],
        ['0164', 'Banco de Desarrollo del Microempresario', false],
        ['0102', 'Banco de Venezuela', true],
        ['0114', 'Bancaribe', true],
        ['0149', 'Banco del Pueblo Soberano', false],
        ['0163', 'Banco del Tesoro', true],
        ['0115', 'Banco Exterior', true],
        ['0003', 'Banco Industrial de Venezuela', false],
        ['0173', 'Banco Internacional de Desarrollo', true],
        ['0105', 'Mercantil', true],
        ['0191', 'Banco Nacional de Crédito', true],
        ['0116', 'BOD', false],
        ['0138', 'Banco Plaza', true],
        ['0108', 'Provincial', true],
        ['0104', 'Venezolano de Crédito', true],
        ['0168', 'Bancrecer', true],
        ['0134', 'Banesco', true],
        ['0177', 'Banfanb', true],
        ['0146', 'Bangente', true],
        ['0174', 'Banplus', true],
        ['0190', 'Citibank', false],
        ['0121', 'Corp Banca', false],
        ['0157', 'Delsur', true],
        ['0151', 'Banco Fondo Común', true],
        ['0601', 'Instituto Municipal de Crédito Popular', true],
        ['0169', 'Mibanco', false],
        ['0137', 'Sofitasa', true],
    ];

    /**
     * @var list<array{string, string, string}>
     */
    private const MOBILE_PREFIXES = [
        ['0412', 'Digitel', 'images/carriers/digitel.svg'],
        ['0422', 'Digitel', 'images/carriers/digitel.svg'],
        ['0414', 'Movistar', 'images/carriers/movistar.png'],
        ['0424', 'Movistar', 'images/carriers/movistar.png'],
        ['0416', 'Movilnet', 'images/carriers/movilnet.png'],
        ['0426', 'Movilnet', 'images/carriers/movilnet.png'],
    ];

    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table): void {
            $table->id();
            $table->char('code', 4)->unique();
            $table->string('name', 100)->unique();
            $table->string('logo_path')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('mobile_prefixes', function (Blueprint $table): void {
            $table->id();
            $table->char('prefix', 4)->unique();
            $table->string('carrier', 40);
            $table->string('logo_path')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('banks')->insert(array_map(fn (array $bank, int $index): array => [
            'code' => $bank[0],
            'name' => $bank[1],
            'logo_path' => $bank[2] ? 'images/banks/'.$bank[0].'.png' : null,
            'position' => $index + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::BANKS, array_keys(self::BANKS)));

        DB::table('mobile_prefixes')->insert(array_map(fn (array $prefix, int $index): array => [
            'prefix' => $prefix[0],
            'carrier' => $prefix[1],
            'logo_path' => $prefix[2],
            'position' => $index + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::MOBILE_PREFIXES, array_keys(self::MOBILE_PREFIXES)));
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_prefixes');
        Schema::dropIfExists('banks');
    }
};
