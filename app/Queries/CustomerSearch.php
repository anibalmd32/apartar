<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Customer;
use App\Support\DocumentNumber;
use App\Support\FullTextTerm;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;

final readonly class CustomerSearch
{
    private function __construct(
        public string $criterion,
        private string $value,
        /** @var list<string> */
        private array $patterns,
    ) {}

    public static function from(mixed $raw): ?self
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);
        $phone = PhoneNumber::tryFromInput($raw);

        if ($phone instanceof PhoneNumber) {
            return new self('phone', $phone->value, []);
        }

        $digits = self::digitsOf($raw);

        if ($digits !== null) {
            return new self('digits', $digits, ['%'.$digits.'%', self::phoneFragment($digits)]);
        }

        $document = DocumentNumber::tryFromInput($raw);

        if ($document instanceof DocumentNumber) {
            return new self('document', $document->value, []);
        }

        if (str_contains($raw, '@')) {
            return new self('email', self::contains(mb_strtolower($raw)), []);
        }

        $term = FullTextTerm::sanitize($raw);

        return $term instanceof FullTextTerm ? new self('name', self::contains(mb_strtolower($term->text)), $term->likePatterns()) : null;
    }

    /**
     * @param  Builder<Customer>  $customers
     * @return Builder<Customer>
     */
    public function apply(Builder $customers): Builder
    {
        return match ($this->criterion) {
            'phone' => $customers->where('customers.phone', $this->value),
            'document' => $customers->where('customers.document_number', $this->value),
            'digits' => $customers->where(fn (Builder $query) => $query
                ->where('customers.document_number', 'like', $this->patterns[0])
                ->orWhere('customers.phone', 'like', $this->patterns[1])),
            'email' => $customers->whereRaw('LOWER(customers.email) LIKE ?', [$this->value]),
            default => $customers->where(fn (Builder $query) => $query
                ->where(function (Builder $name): void {
                    foreach ($this->patterns as $pattern) {
                        $name->where('customers.full_name', 'like', $pattern);
                    }
                })
                ->orWhereRaw('LOWER(customers.email) LIKE ?', [$this->value])),
        };
    }

    private static function contains(string $text): string
    {
        return '%'.addcslashes($text, '%_\\').'%';
    }

    private static function digitsOf(string $raw): ?string
    {
        if (preg_match('/^[\d\s.\-+()]+$/', $raw) !== 1) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', $raw);

        return strlen($digits) >= 3 ? $digits : null;
    }

    private static function phoneFragment(string $digits): string
    {
        $fragment = ltrim($digits, '0');

        return '%'.($fragment === '' ? $digits : $fragment).'%';
    }
}
