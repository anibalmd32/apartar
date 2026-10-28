<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_specifications', function (Blueprint $table): void {
            $table->string('text', 500)->nullable()->after('product_id');
        });

        DB::table('product_specifications')
            ->select(['id', 'label', 'value'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('product_specifications')
                        ->where('id', $row->id)
                        ->update(['text' => $this->joined((string) $row->label, (string) $row->value)]);
                }
            });

        Schema::table('product_specifications', function (Blueprint $table): void {
            $table->string('text', 500)->nullable(false)->change();
            $table->dropColumn(['group', 'label', 'value']);
        });
    }

    public function down(): void
    {
        Schema::table('product_specifications', function (Blueprint $table): void {
            $table->string('group', 80)->nullable()->after('product_id');
            $table->string('label', 120)->default('')->after('group');
            $table->string('value', 500)->default('')->after('label');
        });

        DB::table('product_specifications')
            ->select(['id', 'text'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('product_specifications')
                        ->where('id', $row->id)
                        ->update($this->split((string) $row->text));
                }
            });

        Schema::table('product_specifications', function (Blueprint $table): void {
            $table->dropColumn('text');
        });
    }

    private function joined(string $label, string $value): string
    {
        $label = trim($label);
        $value = trim($value);

        $text = $label === '' ? $value : ($value === '' ? $label : $label.': '.$value);

        return mb_substr($text, 0, 500);
    }

    /**
     * @return array{group: null, label: string, value: string}
     */
    private function split(string $text): array
    {
        $separator = mb_strpos($text, ': ');

        if ($separator === false) {
            return ['group' => null, 'label' => 'Característica', 'value' => $text];
        }

        return [
            'group' => null,
            'label' => mb_substr(mb_substr($text, 0, $separator), 0, 120),
            'value' => mb_substr($text, $separator + 2),
        ];
    }
};
