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

/**
 * A **calendar date** for display.
 *
 * ## The trap this avoids
 *
 * `new Date('2026-04-03')` is not "April 3rd". JavaScript parses a bare ISO date at **UTC
 * midnight**, and `toLocaleDateString` then renders that instant in the browser's own zone.
 * In Colombia (UTC-05) that instant is the previous evening, so a financial due date of
 * `2026-04-03` was displayed as April 2nd — on a screen whose entire purpose is telling an
 * operator when money is due.
 *
 * The fix is to never route a calendar-only value through an instant. The components are
 * read as text and formatted as text, so nothing can shift them by a day.
 *
 * ## What this function accepts
 *
 * A value whose first ten characters are `YYYY-MM-DD`. That covers `2026-04-03` and
 * `2026-04-03T00:00:00.000000Z`, which is how the API sends some timestamps. For a
 * **timestamp** the date is still the leading part of the string, and a timestamp whose time
 * of day matters belongs in `fechaHora()`, which is where a real instant is wanted.
 *
 * A value that is not a date at all is returned unchanged, so a broken record is visible as
 * what it is rather than as "Invalid Date".
 */
export function fecha(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    const soloFecha = fechaComoTexto(iso);

    if (soloFecha !== null) {
        return formatearFecha(soloFecha);
    }

    // Not `YYYY-MM-DD` at the front: a real instant, or something broken. Parsing it is safe
    // because a full timestamp carries its own offset and cannot shift.
    const parsed = new Date(iso);

    if (Number.isNaN(parsed.getTime())) {
        return iso;
    }

    return parsed.toLocaleDateString('es-CO', { dateStyle: 'medium' });
}

/**
 * The `YYYY-MM-DD` components of a value, or null when it does not start with them.
 *
 * Read as **text**, deliberately: `slice` on a string cannot involve a time zone, which is
 * the entire reason this exists.
 */
export function fechaComoTexto(iso: string | null | undefined): string | null {
    if (!iso || typeof iso !== 'string') {
        return null;
    }

    const coincidencia = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);

    return coincidencia === null ? null : `${coincidencia[1]}-${coincidencia[2]}-${coincidencia[3]}`;
}

/**
 * Format `YYYY-MM-DD` as `3 abr 2026`, in Spanish, without touching a time zone.
 *
 * The month names are the same list `etiquetaMes()` uses rather than `Intl`, because the
 * short `Intl` month depends on the browser's locale data and has been seen rendering
 * differently for the same date.
 */
function formatearFecha(iso: string): string {
    const [, anio, mes, dia] = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso) ?? [];

    if (!anio) {
        return iso;
    }

    const meses = [
        'ene', 'feb', 'mar', 'abr', 'may', 'jun',
        'jul', 'ago', 'sep', 'oct', 'nov', 'dic',
    ];

    const indice = Number.parseInt(mes, 10) - 1;

    if (Number.isNaN(indice) || indice < 0 || indice > 11) {
        return iso;
    }

    return `${Number.parseInt(dia, 10)} ${meses[indice]} ${anio}`;
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
