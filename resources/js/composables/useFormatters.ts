/**
 * Money, dates and months for the interface.
 *
 * The value is always a whole number of pesos, exactly as the server sends it. The
 * formatting lives here and nowhere else, so two screens cannot disagree about what
 * a debt is worth, and so no screen is tempted to receive a formatted string.
 *
 * `es-CO` is used for the separators Colombian operators read, and nothing here
 * rounds: an amount in pesos has no cents, and showing "1.234.567" for 1234567 is
 * not an approximation.
 */

const PESOS = new Intl.NumberFormat('es-CO', {
    maximumFractionDigits: 0,
    minimumFractionDigits: 0,
});

const MONEY = new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
});

/**
 * Pesos with separators, for a column where every row is money.
 *
 * The currency symbol is left out because a column headed "Saldo" already says what
 * it is, and repeating "$" twenty five times makes the numbers harder to compare.
 */
export function pesos(value: number | null | undefined): string {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '—';
    }

    return PESOS.format(Math.trunc(value));
}

/** Pesos with the currency mark, for a single figure that is read on its own. */
export function pesosConSimbolo(value: number | null | undefined): string {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '—';
    }

    return MONEY.format(Math.trunc(value));
}

/**
 * Read what an operator typed back into a whole number of pesos.
 *
 * Grouping separators are dropped rather than rejected: somebody pasting "235.000"
 * into the amount field means two hundred and thirty five thousand pesos, and
 * refusing it would be pedantry about a real intent.
 *
 * Returns null when the text is not a whole number. Callers must handle that rather
 * than defaulting to zero: a zero would silently book a payment of nothing.
 */
export function pesosDesdeTexto(value: string | number | null | undefined): number | null {
    if (value === null || value === undefined) {
        return null;
    }

    if (typeof value === 'number') {
        return Number.isInteger(value) ? value : null;
    }

    const limpio = value.replace(/[\s\u00a0]/g, '').replace(',', '.').trim();

    if (limpio === '') {
        return null;
    }

    // A dot is both a grouping separator and a decimal mark, so it cannot simply be
    // stripped. `235.000` is two hundred and thirty five thousand; `235000.50` is
    // two hundred and thirty five thousand pesos and fifty centavos, which this
    // system cannot represent. Reading the first as the second, by deleting the dot,
    // would book a payment of 23.500.050 against an intent of 235.000 — so the shape
    // is checked instead: grouping always comes in exact groups of three.
    if (/^-?\d{1,3}(\.\d{3})+$/.test(limpio)) {
        const entero = limpio.replace(/\./g, '');

        return Number.isSafeInteger(Number.parseInt(entero, 10)) ? Number.parseInt(entero, 10) : null;
    }

    // Anything left with a dot is a decimal part, and there are no centavos here.
    if (limpio.includes('.') || !/^-?\d+$/.test(limpio)) {
        return null;
    }

    const parsed = Number.parseInt(limpio, 10);

    return Number.isSafeInteger(parsed) ? parsed : null;
}

/** A `YYYY-MM` month, for a date input or a query parameter. */
export function mesActual(): string {
    const hoy = new Date();

    return `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}`;
}

export function mesDesdeIso(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    return iso.slice(0, 7);
}

/** `2026-10` as `Octubre 2026`, for a heading. */
export function etiquetaMes(key: string): string {
    const meses = [
        'Enero',
        'Febrero',
        'Marzo',
        'Abril',
        'Mayo',
        'Junio',
        'Julio',
        'Agosto',
        'Septiembre',
        'Octubre',
        'Noviembre',
        'Diciembre',
    ];

    const [anio, mes] = key.split('-');
    const indice = Number.parseInt(mes ?? '', 10) - 1;

    if (!anio || Number.isNaN(indice) || indice < 0 || indice > 11) {
        return key;
    }

    return `${meses[indice]} ${anio}`;
}

/** An ISO date for display, without the time. */
export function fecha(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    const parsed = new Date(iso);

    if (Number.isNaN(parsed.getTime())) {
        return iso;
    }

    return parsed.toLocaleDateString('es-CO', { dateStyle: 'medium' });
}

export function fechaHora(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    const parsed = new Date(iso);

    if (Number.isNaN(parsed.getTime())) {
        return iso;
    }

    return parsed.toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' });
}

/** Today, for a default date field. */
export function hoy(): string {
    const ahora = new Date();
    const mes = String(ahora.getMonth() + 1).padStart(2, '0');
    const dia = String(ahora.getDate()).padStart(2, '0');

    return `${ahora.getFullYear()}-${mes}-${dia}`;
}
