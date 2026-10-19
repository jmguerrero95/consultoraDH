import { describe, expect, it } from 'vitest';

import { normalizeForSpeech, numberToSpanishWords } from '@/utils/speech';

/**
 * Spoken-form normalisation.
 *
 * These assertions exist because the defect was audible, not visible: a reply
 * showing "15.000 COP" was read as a number run and a date was read as digits.
 * The reply on screen and in the database is never changed — only the string
 * handed to `speechSynthesis` is.
 */
describe('utils/speech — numbers', () => {
    it('spells whole numbers the way they are said', () => {
        expect(numberToSpanishWords(0)).toBe('cero');
        expect(numberToSpanishWords(15)).toBe('quince');
        expect(numberToSpanishWords(21)).toBe('veintiuno');
        expect(numberToSpanishWords(45)).toBe('cuarenta y cinco');
        expect(numberToSpanishWords(100)).toBe('cien');
        expect(numberToSpanishWords(101)).toBe('ciento uno');
        expect(numberToSpanishWords(1000)).toBe('mil');
        expect(numberToSpanishWords(15000)).toBe('quince mil');
        expect(numberToSpanishWords(21000)).toBe('veintiún mil');
        expect(numberToSpanishWords(1500000)).toBe('un millón quinientos mil');
    });
});

describe('utils/speech — reply to speech', () => {
    it('reads a COP amount in words', () => {
        expect(normalizeForSpeech('El saldo es 15.000 COP.')).toBe(
            'El saldo es quince mil pesos colombianos.',
        );
    });

    it('reads an amount followed by "pesos"', () => {
        expect(normalizeForSpeech('Pagó 1.500.000 pesos')).toBe('Pagó un millón quinientos mil pesos');
    });

    it('reads a dollar-marked amount', () => {
        expect(normalizeForSpeech('Total $ 250.000')).toBe('Total doscientos cincuenta mil pesos colombianos');
    });

    it('reads an ISO date as a date', () => {
        expect(normalizeForSpeech('Corte al 2026-10-19')).toBe(
            'Corte al diecinueve de octubre de dos mil veintiséis',
        );
    });

    it('reads a slash date as a date', () => {
        expect(normalizeForSpeech('Vence el 19/10/2026.')).toBe(
            'Vence el diecinueve de octubre de dos mil veintiséis.',
        );
    });

    it('leaves ordinary prose untouched', () => {
        expect(normalizeForSpeech('La cartera vencida asciende a 45 millones.')).toBe(
            'La cartera vencida asciende a 45 millones.',
        );
    });

    it('does not rewrite a bare table figure that carries no currency', () => {
        // The table is already turned into sentences elsewhere; a plain figure
        // is left exactly as written rather than guessed at.
        expect(normalizeForSpeech('Saldo: 120.000.')).toBe('Saldo: 120.000.');
    });
});
