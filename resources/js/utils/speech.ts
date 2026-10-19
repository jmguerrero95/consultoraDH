/**
 * Turn a written reply into words a speech engine reads correctly.
 *
 * ## The problem this solves
 *
 * `speechSynthesis` reads the literal string. "15.000 COP" comes out as a
 * decimal-ish mumble, a date "2026-10-19" is read digit by digit or as a
 * subtraction, and a currency figure without its unit is meaningless. The
 * person asked for their portfolio, so the reply should be read the way a
 * colleague would say it: "quince mil pesos colombianos".
 *
 * ## Only the spoken form changes
 *
 * This never touches the reply on screen or in the database. It is applied at
 * the last moment, inside `speak()`, to the text handed to the browser. Bare
 * numbers are deliberately left alone: "45 millones" is already spoken well,
 * and only an explicitly marked amount — a `COP`/`pesos` suffix or a leading
 * `$` — is rewritten, so a table of plain figures is not distorted.
 */

const UNITS = [
    'cero', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
    'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete',
    'dieciocho', 'diecinueve', 'veinte', 'veintiuno', 'veintidós', 'veintitrés',
    'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve',
];

const TENS = ['', '', '', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];

const HUNDREDS = [
    '', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos',
    'seiscientos', 'setecientos', 'ochocientos', 'novecientos',
];

const MONTHS = [
    'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
    'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
];

/**
 * A whole number written out in Spanish, 0 … 999 999 999 999.
 *
 * `apocope` turns "uno" into "un" where it precedes "mil" or "millón" ("un
 * millón", "veintiún mil"), which is how the number is actually said.
 */
export function numberToSpanishWords(value: number): string {
    if (!Number.isFinite(value)) {
        return '';
    }

    const n = Math.trunc(Math.abs(value));

    if (n === 0) {
        return 'cero';
    }

    return spell(n).trim();
}

function spell(n: number): string {
    if (n < 30) {
        return UNITS[n] ?? '';
    }

    if (n < 100) {
        const ten = Math.floor(n / 10);
        const unit = n % 10;

        return unit === 0 ? TENS[ten] : `${TENS[ten]} y ${UNITS[unit]}`;
    }

    if (n < 1000) {
        if (n === 100) {
            return 'cien';
        }

        const hundred = Math.floor(n / 100);
        const rest = n % 100;

        return rest === 0 ? HUNDREDS[hundred] : `${HUNDREDS[hundred]} ${spell(rest)}`;
    }

    // Millions first: 1 500 000 is "un millón quinientos mil", not
    // "mil quinientos mil".
    if (n >= 1_000_000) {
        const million = Math.floor(n / 1_000_000);
        const remainder = n % 1_000_000;

        const prefix = million === 1
            ? 'un millón'
            : `${apocope(spell(million))} millones`;

        return remainder === 0 ? prefix : `${prefix} ${spell(remainder)}`;
    }

    const thousand = Math.floor(n / 1000);
    const rest = n % 1000;

    const prefix = thousand === 1 ? 'mil' : `${apocope(spell(thousand))} mil`;

    return rest === 0 ? prefix : `${prefix} ${spell(rest)}`;
}

/**
 * The apocopated form a number takes before "mil" / "millones".
 *
 * "veintiuno" becomes "veintiún", a trailing "uno" becomes "un": "veintiún mil",
 * "ciento un mil", "un millón".
 */
function apocope(word: string): string {
    return word
        .replace(/veintiuno$/, 'veintiún')
        .replace(/uno$/, 'un');
}

/**
 * Read a date the way it is said: "19 de octubre de 2026".
 *
 * Accepts `YYYY-MM-DD` (the ISO form in the data) and `DD/MM/YYYY` (the form a
 * person types). An impossible month is left untouched rather than guessed at.
 */
export function dateToSpanishWords(year: number, month: number, day: number): string | null {
    if (month < 1 || month > 12 || day < 1 || day > 31) {
        return null;
    }

    const spelledYear = year >= 1000 && year < 3000
        ? numberToSpanishWords(year)
        : String(year);

    return `${numberToSpanishWords(day)} de ${MONTHS[month - 1]} de ${spelledYear}`;
}

/**
 * The spoken form of a written reply.
 *
 * Order matters: dates first, so a `2026-10-19` is not partially consumed by
 * the amount rule, then currency.
 */
export function normalizeForSpeech(text: string): string {
    let result = text;

    // ISO dates: 2026-10-19 → "diecinueve de octubre de dos mil veintiséis".
    result = result.replace(/\b(\d{4})-(\d{2})-(\d{2})\b/g, (match, y: string, m: string, d: string) => {
        const spoken = dateToSpanishWords(Number(y), Number(m), Number(d));

        return spoken ?? match;
    });

    // Slash dates: 19/10/2026 → "diecinueve de octubre de dos mil veintiséis".
    result = result.replace(/\b(\d{1,2})\/(\d{1,2})\/(\d{4})\b/g, (match, d: string, m: string, y: string) => {
        const spoken = dateToSpanishWords(Number(y), Number(m), Number(d));

        return spoken ?? match;
    });

    // An amount marked with COP or "pesos": "15.000 COP", "COP 15.000",
    // "15.000 pesos". The grouped thousands separator (dots or spaces) and the
    // optional decimal comma are understood.
    const amount = '([0-9]{1,3}(?:[.\\s][0-9]{3})*(?:,[0-9]+)?|[0-9]+)';
    const currency = new RegExp(`(?:(?:COP|pesos?)\\s+${amount}|${amount}\\s*(?:COP|pesos?))`, 'gi');

    result = result.replace(currency, (match, ...groups: unknown[]) => {
        const raw = (groups[0] ?? groups[1]) as string | undefined;

        if (!raw) {
            return match;
        }

        const whole = raw.split(',')[0] ?? raw;
        const integer = Number(whole.replace(/[.\s]/g, ''));

        if (!Number.isFinite(integer) || integer < 0) {
            return match;
        }

        const decimals = raw.includes(',') ? (raw.split(',')[1] ?? '') : '';

        const spoken = numberToSpanishWords(integer);

        // "COP" is Colombian pesos; the bare word "pesos" keeps the unit the
        // reply already used.
        const unit = /cop/i.test(match) ? 'pesos colombianos' : 'pesos';

        return decimals === '' ? `${spoken} ${unit}` : `${spoken} ${decimals} ${unit}`;
    });

    // A bare leading dollar sign: "$ 15.000" → "quince mil pesos colombianos".
    result = result.replace(/\$\s*([0-9]{1,3}(?:[.][0-9]{3})*|\d+)/g, (match, raw: string) => {
        const integer = Number(raw.replace(/\./g, ''));

        if (!Number.isFinite(integer)) {
            return match;
        }

        return `${numberToSpanishWords(integer)} pesos colombianos`;
    });

    return result;
}
