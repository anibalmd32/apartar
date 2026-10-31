import { AppRoot } from '@/app-root';
import { useQueryClient } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

function NeedsQueryClient() {
    useQueryClient();

    return <p>con cliente</p>;
}

it('da un QueryClient a toda página, también a las que lo usan fuera de su layout', () => {
    render(
        <AppRoot>
            <NeedsQueryClient />
        </AppRoot>,
    );

    expect(screen.getByText('con cliente')).toBeInTheDocument();
});
