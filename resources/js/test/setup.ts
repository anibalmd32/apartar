import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

// React Testing Library no desmonta solo cuando `globals` está activo con
// `restoreMocks`; sin esto, un test ve el DOM que dejó el anterior.
afterEach(() => {
    cleanup();
});
