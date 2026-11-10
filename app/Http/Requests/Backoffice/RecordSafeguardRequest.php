<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Payment\SafeguardData;
use App\Domain\Payment\ReceiptFile;
use App\Domain\Safeguard\SafeguardDestination;
use App\Http\Requests\Portal\StoreReceiptRequest;
use App\Models\Layaway;
use App\Models\Payment;
use App\Rules\RealMimeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

final class RecordSafeguardRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payment = $this->route('payment');
        $layaway = $payment instanceof Payment ? Layaway::query()->find($payment->layaway_id) : null;

        return $layaway instanceof Layaway && $this->user()?->can('manage', $layaway) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'destination' => ['required', 'string', Rule::enum(SafeguardDestination::class)],
            'reference' => ['nullable', 'string', 'max:'.SafeguardData::REFERENCE_MAX],
            'proof' => [
                'nullable',
                'file',
                'max:'.(ReceiptFile::maxSizeMb() * 1024),
                RealMimeType::receipts(),
                StoreReceiptRequest::withinPixelLimit(),
            ],
            'note' => ['nullable', 'string', 'max:'.SafeguardData::NOTE_MAX],
            'remove_proof' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'destination' => 'dónde lo resguardas',
            'reference' => 'referencia',
            'proof' => 'comprobante',
            'note' => 'nota',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'destination.required' => 'Elige dónde resguardas este pago.',
            'proof.max' => sprintf('El comprobante pesa más de %d MB. Si es un PDF, sube mejor una captura.', ReceiptFile::maxSizeMb()),
        ];
    }

    public function toData(): SafeguardData
    {
        $proof = $this->file('proof');

        return new SafeguardData(
            destination: SafeguardDestination::from($this->string('destination')->toString()),
            reference: $this->string('reference')->toString(),
            proof: $proof instanceof UploadedFile ? $proof : null,
            note: $this->string('note')->toString(),
            removeProof: $this->boolean('remove_proof'),
        );
    }
}
