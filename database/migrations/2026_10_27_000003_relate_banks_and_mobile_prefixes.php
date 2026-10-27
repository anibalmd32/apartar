<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const BANK_TABLES = ['payment_accounts', 'receipts', 'subscription_receipts'];

    private const PHONE_TABLES = ['customers' => 'phone', 'organizations' => 'phone', 'payment_accounts' => 'identifier'];

    /**
     * @var array<string, string>
     */
    private const ALIASES = [
        'venezuela' => '0102',
        'bdv' => '0102',
        'banesco banco universal' => '0134',
        'banco mercantil' => '0105',
        'mercantil banco' => '0105',
        'banco provincial' => '0108',
        'bbva provincial' => '0108',
        'provincial bbva' => '0108',
        'banco provincial bbva' => '0108',
        'bnc' => '0191',
        'banco del caribe' => '0114',
        'banco occidental de descuento' => '0116',
        'exterior' => '0115',
        'tesoro' => '0163',
        'bicentenario' => '0175',
        'plaza' => '0138',
        'activo' => '0171',
        'caroni' => '0128',
        'bfc' => '0151',
        'fondo comun' => '0151',
        'banco venezolano de credito' => '0104',
        'delsur banco universal' => '0157',
        '100%banco' => '0156',
        'banplus banco universal' => '0174',
        'bancamiga banco universal' => '0172',
        'banco sofitasa' => '0137',
    ];

    public function up(): void
    {
        foreach (self::BANK_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreignId('bank_id')->nullable()->after('bank')->constrained('banks')->restrictOnDelete();
            });
        }

        foreach (self::PHONE_TABLES as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->foreignId('mobile_prefix_id')->nullable()->after($column)->constrained('mobile_prefixes')->restrictOnDelete();
            });
        }

        $this->relateBanks();
        $this->relatePhones();
    }

    public function down(): void
    {
        foreach (array_keys(self::PHONE_TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('mobile_prefix_id');
            });
        }

        foreach (self::BANK_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('bank_id');
            });
        }
    }

    private function relateBanks(): void
    {
        $banks = DB::table('banks')->get(['id', 'code', 'name']);
        $byKey = [];

        foreach ($banks as $bank) {
            $byKey[self::key((string) $bank->name)] = $bank;
            $byKey[(string) $bank->code] = $bank;
        }

        foreach (self::ALIASES as $alias => $code) {
            $byKey[$alias] = $byKey[$code];
        }

        foreach (self::BANK_TABLES as $table) {
            foreach (DB::table($table)->whereNotNull('bank')->distinct()->pluck('bank') as $typed) {
                $bank = $byKey[self::key((string) $typed)] ?? null;

                if ($bank !== null) {
                    DB::table($table)->where('bank', $typed)->update(['bank_id' => $bank->id, 'bank' => $bank->name]);
                }
            }
        }
    }

    private function relatePhones(): void
    {
        $prefixes = DB::table('mobile_prefixes')->pluck('id', 'prefix');

        foreach (self::PHONE_TABLES as $table => $column) {
            DB::table($table)
                ->whereNotNull($column)
                ->when($table === 'payment_accounts', fn ($query) => $query->where('method', 'pago_movil'))
                ->orderBy('id')
                ->select(['id', $column])
                ->chunkById(500, function ($rows) use ($table, $column, $prefixes): void {
                    foreach ($rows as $row) {
                        $prefix = self::mobilePrefixOf((string) $row->{$column});

                        if ($prefix !== null && isset($prefixes[$prefix])) {
                            DB::table($table)->where('id', $row->id)->update(['mobile_prefix_id' => $prefixes[$prefix]]);
                        }
                    }
                });
        }
    }

    private static function mobilePrefixOf(string $phone): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 12 && str_starts_with($digits, '58')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 10) {
            $digits = '0'.$digits;
        }

        return strlen($digits) === 11 ? substr($digits, 0, 4) : null;
    }

    private static function key(string $value): string
    {
        return Str::lower(Str::ascii(trim($value)));
    }
};
