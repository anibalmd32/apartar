import { RateConfirmations, ratePending } from '@/features/receipts/components/StaleRateConfirmation';
import { type ApprovalPreviewView } from '@/types/receipt';
import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

const deviation = { token: '4.25@42.50000000', percent: 90, above: false, manual: '4,25', reference: '42,50', sourceLabel: 'BCV' };
const preview = { staleRate: null, rateDeviation: deviation } as unknown as ApprovalPreviewView;

it('explica cuánto se aleja la tasa escrita y pide confirmarla', () => {
    const onChange = vi.fn();

    render(<RateConfirmations preview={preview} values={{ confirm_stale_rate: null, confirm_rate_deviation: null }} onChange={onChange} errors={{}} />);

    expect(screen.getByText(/está 90 % por debajo de la tasa BCV del día \(42,50\)/)).toBeInTheDocument();

    fireEvent.click(screen.getByLabelText('Confirmo la tasa de 4,25'));

    expect(onChange).toHaveBeenCalledWith('confirm_rate_deviation', '4.25@42.50000000');
});

it('bloquea el envío hasta confirmar la tasa', () => {
    expect(ratePending(preview, { confirm_stale_rate: null, confirm_rate_deviation: null })).toBe(true);
    expect(ratePending(preview, { confirm_stale_rate: null, confirm_rate_deviation: deviation.token })).toBe(false);
    expect(ratePending({ ...preview, rateDeviation: null }, { confirm_stale_rate: null, confirm_rate_deviation: null })).toBe(false);
});
