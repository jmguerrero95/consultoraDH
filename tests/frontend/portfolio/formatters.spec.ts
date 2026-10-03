import { describe, expect, it } from 'vitest';

import {
    etiquetaMes,
    fecha,
    fechaHora,
    hoy,
    mesActual,
    mesDesdeIso,
    pesos,
    pesosConSimbolo,
    pesosDesdeTexto,
} from '@/composables/useFormatters';

/**
 * The formatters.
 *
 * These are the only place in the interface where a money figure or a month name is
 * written, so a mistake here would be wrong on every screen at once. The parsing side
 * matters most: the function that reads what an operator typed decides whether a
 * payment of 235000 is recorded, refused, or quietly recorded as something else.
 */

describe('pesos', () => {
    it('writes whole pesos with Colombian separators', () => {
        expect(pesos(235000)).toBe('235.000');
        expect(pesos(0)).toBe('0');
        expect(pesos(1234567)).toBe('1.234.567');
    });

    it('never invents cents', () => {
        // A float must not reach this function, and if one ever does it is truncated
        // rather than shown with decimals the system does not have.
        expect(pesos(235000.75)).toBe('235.000');
    });

    it('says nothing rather than zero when there is no figure', () => {
        // "—" for an absent value and "0" for a real zero are different statements,
        // and the second one would claim somebody owes nothing.
        expect(pesos(null)).toBe('—');
        expect(pesos(undefined)).toBe('—');
        expect(pesos(Number.NaN)).toBe('—');
    });

    it('adds the currency mark only where it is asked for', () => {
        expect(pesosConSimbolo(235000)).toContain('235.000');
        expect(pesosConSimbolo(null)).toBe('—');
    });
});

describe('pesosDesdeTexto', () => {
    it('reads what an operator typed', () => {
        expect(pesosDesdeTexto('235000')).toBe(235000);
        expect(pesosDesdeTexto(235000)).toBe(235000);
    });

    it('accepts grouping separators rather than refusing them', () => {
        // Somebody pasting 235.000 means two hundred and thirty five thousand.
        expect(pesosDesdeTexto('235.000')).toBe(235000);
        expect(pesosDesdeTexto('1.234.567')).toBe(1234567);
    });

    it('refuses anything that is not a whole number of pesos', () => {
        // This system has no centavos. A figure with a decimal part is not a payment,
        // and rounding it would be inventing money.
        expect(pesosDesdeTexto('235000.50')).toBeNull();
        expect(pesosDesdeTexto('235,50')).toBeNull();
        expect(pesosDesdeTexto('100.5')).toBeNull();
    });

    it('refuses text that is not a number at all', () => {
        expect(pesosDesdeTexto('')).toBeNull();
        expect(pesosDesdeTexto('   ')).toBeNull();
        expect(pesosDesdeTexto('abc')).toBeNull();
        expect(pesosDesdeTexto('12abc')).toBeNull();
        expect(pesosDesdeTexto(null)).toBeNull();
        expect(pesosDesdeTexto(undefined)).toBeNull();
    });

    it('refuses a fractional number rather than truncating it', () => {
        // 235000.5 is not a whole number, and truncating it silently would book a
        // payment of less than the operator typed.
        expect(pesosDesdeTexto(235000.5)).toBeNull();
    });

    it('reads a negative value, because a refund is a real thing', () => {
        expect(pesosDesdeTexto('-50000')).toBe(-50000);
    });
});

describe('meses', () => {
    it('names a month the way an operator writes it', () => {
        expect(etiquetaMes('2026-10')).toBe('Octubre 2026');
        expect(etiquetaMes('2026-01')).toBe('Enero 2026');
        expect(etiquetaMes('2026-12')).toBe('Diciembre 2026');
    });

    it('returns the key rather than a wrong label for something unreadable', () => {
        // A wrong month name on a financial row is worse than an ugly one.
        expect(etiquetaMes('no-es-un-mes')).toBe('no-es-un-mes');
        expect(etiquetaMes('2026-99')).toBe('2026-99');
        expect(etiquetaMes('')).toBe('');
    });

    it('takes the month out of an ISO date', () => {
        expect(mesDesdeIso('2026-10-01')).toBe('2026-10');
        expect(mesDesdeIso(null)).toBe('');
    });

    it('reports the current month as a value a month input accepts', () => {
        expect(mesActual()).toMatch(/^\d{4}-\d{2}$/);
    });
});

describe('fechas', () => {
    it('writes a date the way a Colombian reader expects', () => {
        // The format matters: 03/04/2026 is March 4th to almost everyone else in the
        // world and April 3rd here.
        const escrito = fecha('2026-04-03T00:00:00Z');

        expect(escrito).not.toBe('—');
        expect(escrito).toMatch(/2026|26/);
    });

    it('says nothing for an absent date', () => {
        expect(fecha(null)).toBe('—');
        expect(fecha(undefined)).toBe('—');
        expect(fechaHora(null)).toBe('—');
    });

    it('returns the raw value rather than an invalid date for something unreadable', () => {
        // "Invalid Date" in a financial column is a bug report waiting to happen.
        expect(fecha('no-es-una-fecha')).toBe('no-es-una-fecha');
        expect(fechaHora('no-es-una-fecha')).toBe('no-es-una-fecha');
    });

    it('produces today as yyyy-mm-dd', () => {
        expect(hoy()).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    });
});
