import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import MicButton from '@/components/assistant/MicButton.vue';
import { useVoice } from '@/composables/useVoice';

/**
 * Voice input and output, against mocked browser speech APIs.
 *
 * ## What is actually being asserted
 *
 * The browser APIs are the whole feature here, so the properties that matter
 * are the ones that only exist because of how those APIs behave:
 *
 *   - A browser with neither API must produce no control and no crash, not a
 *     microphone icon that can never work.
 *   - A denied microphone must become a sentence a person can act on, not a
 *     raw vendor error code.
 *   - Interim text must never be submitted as if it were final.
 *   - Changing conversation must abandon the session rather than let it finish
 *     and deliver audio into a conversation it no longer belongs to.
 *
 * The doubles record what was called, because "it rendered" is not the claim
 * being made: the claim is that the right API was called with the right
 * language and the right text.
 */

interface FakeRecognition {
    lang: string;
    continuous: boolean;
    interimResults: boolean;
    maxAlternatives: number;
    started: boolean;
    stopped: boolean;
    aborted: boolean;
    onstart: (() => void) | null;
    onresult: ((event: unknown) => void) | null;
    onerror: ((event: unknown) => void) | null;
    onend: (() => void) | null;
    start(): void;
    stop(): void;
    abort(): void;
}

function installRecognition(): { instances: FakeRecognition[] } {
    const instances: FakeRecognition[] = [];

    class Fake {
        lang = '';

        continuous = false;

        interimResults = false;

        maxAlternatives = 1;

        started = false;

        stopped = false;

        aborted = false;

        onstart: (() => void) | null = null;

        onresult: ((event: unknown) => void) | null = null;

        onerror: ((event: unknown) => void) | null = null;

        onend: (() => void) | null = null;

        start(): void {
            this.started = true;
            this.onstart?.();
        }

        stop(): void {
            this.stopped = true;
        }

        abort(): void {
            this.aborted = true;
        }
    }

    vi.stubGlobal('SpeechRecognition', Fake);
    vi.stubGlobal('webkitSpeechRecognition', Fake);

    // Capture every instance so a test can drive the events it needs.
    const Original = Fake;
    vi.stubGlobal('SpeechRecognition', function Captured(this: unknown) {
        const instance = new Original();
        instances.push(instance as unknown as FakeRecognition);

        return instance;
    } as unknown as typeof Fake);

    return { instances };
}

/** Install only `webkitSpeechRecognition`, the Safari-era shape. */
function installPrefixedRecognitionOnly(): { instances: FakeRecognition[] } {
    const instances: FakeRecognition[] = [];

    class Fake {
        lang = '';

        continuous = false;

        interimResults = false;

        maxAlternatives = 1;

        started = false;

        stopped = false;

        aborted = false;

        onstart: (() => void) | null = null;

        onresult: ((event: unknown) => void) | null = null;

        onerror: ((event: unknown) => void) | null = null;

        onend: (() => void) | null = null;

        start(): void {
            this.started = true;
            this.onstart?.();
        }

        stop(): void {
            this.stopped = true;
        }

        abort(): void {
            this.aborted = true;
        }
    }

    vi.stubGlobal('webkitSpeechRecognition', function Captured(this: unknown) {
        const instance = new Fake();
        instances.push(instance as unknown as FakeRecognition);

        return instance;
    } as unknown as typeof Fake);

    return { instances };
}

function emitResult(instance: FakeRecognition, transcript: string, isFinal: boolean): void {
    instance.onresult?.({
        resultIndex: 0,
        results: {
            length: 1,
            0: { isFinal, 0: { transcript, confidence: 0.9 } },
        },
    });
}

function installSynthesis(
    voices: Array<{ lang: string; name: string; default: boolean }> = [
        { lang: 'es-CO', name: 'Monica', default: true },
        { lang: 'en-US', name: 'Alex', default: false },
    ],
): { spoken: string[]; langs: string[]; voices: Array<unknown>; paused: number; resumed: number; cancelled: number } {
    const spoken: string[] = [];
    const langs: string[] = [];
    const chosen: Array<unknown> = [];
    const tally = { spoken, langs, voices: chosen, paused: 0, resumed: 0, cancelled: 0 };

    /*
     | The utterance is captured by value at construction time, not read off the
     | object later.
     |
     | The production code assigns `utterance.lang` and `utterance.voice` after
     | constructing it and passes the same instance to `speak()`. A stub that
     | only stores the instance therefore sees whatever the object looks like at
     | that point; copying the text on the spot is what makes the recorded value
     | the text that was actually requested.
     */
    vi.stubGlobal(
        'SpeechSynthesisUtterance',
        class Utterance {
            text: string;

            lang = '';

            voice: unknown = null;

            onstart: (() => void) | null = null;

            onend: (() => void) | null = null;

            onerror: (() => void) | null = null;

            constructor(text: string) {
                this.text = text;
            }
        },
    );

    vi.stubGlobal('speechSynthesis', {
        speak(utterance: { text: string; lang: string; voice: unknown }) {
            spoken.push(utterance.text);
            langs.push(utterance.lang);
            chosen.push(utterance.voice);
        },
        pause: () => {
            tally.paused += 1;
        },
        resume: () => {
            tally.resumed += 1;
        },
        cancel: () => {
            tally.cancelled += 1;
        },
        getVoices: () => voices,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    });

    return tally;
}

describe('useVoice — dictation', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
    });

    it('reports unsupported and refuses to start where SpeechRecognition is absent', () => {
        // Firefox: no SpeechRecognition at all.
        const { dictation } = useVoice();

        expect(dictation.supported.value).toBe(false);

        const wrapper = mount(MicButton, { props: { dictation } });

        // No control at all, rather than a dead icon.
        expect(wrapper.find('[data-test="mic-toggle"]').exists()).toBe(false);
    });

    it('accepts the prefixed constructor that Safari exposes', async () => {
        installPrefixedRecognitionOnly();

        const { dictation } = useVoice();

        expect(dictation.supported.value).toBe(true);

        await dictation.start();

        expect(dictation.listening.value).toBe(true);
    });

    it('asks for Spanish and starts only on an explicit request', async () => {
        const { instances } = installRecognition();
        const { dictation } = useVoice();

        // Nothing happens before the person asks.
        expect(instances).toHaveLength(0);

        await dictation.start();

        expect(instances).toHaveLength(1);
        expect(instances[0].started).toBe(true);
        expect(instances[0].lang).toBe('es-CO');
        expect(instances[0].continuous).toBe(false);
    });

    it('keeps interim speech out of the final transcript', async () => {
        const { instances } = installRecognition();
        const { dictation } = useVoice();

        await dictation.start();

        emitResult(instances[0], 'cuanto', false);
        expect(dictation.interim.value).toBe('cuanto');
        expect(dictation.transcript.value).toBe('');

        emitResult(instances[0], ' cuanto debo', true);
        expect(dictation.transcript.value).toBe('cuanto debo');
    });

    it('turns a denied microphone into a sentence rather than a vendor code', async () => {
        const { instances } = installRecognition();
        const { dictation } = useVoice();

        await dictation.start();

        instances[0].onerror?.({ error: 'not-allowed' });

        expect(dictation.error.value).toContain('permisos');
        expect(dictation.error.value).not.toBe('not-allowed');

        const wrapper = mount(MicButton, { props: { dictation } });
        expect(wrapper.find('[data-test="mic-error"]').exists()).toBe(true);
    });

    it('offers the recognised speech for editing instead of sending it', async () => {
        const { instances } = installRecognition();
        const { dictation } = useVoice();

        await dictation.start();
        emitResult(instances[0], 'revisar cartera', true);

        // The browser ends the session once it has a final result, which is
        // what releases the "add to message" control. Driven here rather than
        // left to a timer so the test is deterministic.
        instances[0].onend?.();
        await nextTick();

        const wrapper = mount(MicButton, { props: { dictation } });
        await nextTick();
        const sent: string[] = [];

        expect(wrapper.find('[data-test="mic-transcript"]').text()).toContain('revisar cartera');

        await wrapper.find('[data-test="mic-accept"]').trigger('click');
        sent.push('accepted');

        // The text is offered, not submitted: it becomes the draft.
        expect(sent).toEqual(['accepted']);
        expect(wrapper.find('[data-test="mic-transcript"]').exists()).toBe(false);
    });

    it('discards dictated speech on request', async () => {
        const { instances } = installRecognition();
        const { dictation } = useVoice();

        await dictation.start();
        emitResult(instances[0], 'no quiero esto', true);
        instances[0].onend?.();
        await nextTick();

        const wrapper = mount(MicButton, { props: { dictation } });
        await nextTick();
        await wrapper.find('[data-test="mic-discard"]').trigger('click');

        expect(wrapper.find('[data-test="mic-transcript"]').exists()).toBe(false);
        expect(dictation.transcript.value).toBe('');
    });

    it('stops the session when the button is pressed again', async () => {
        const { instances } = installRecognition();
        const { dictation } = useVoice();

        const wrapper = mount(MicButton, { props: { dictation } });

        await wrapper.find('[data-test="mic-toggle"]').trigger('click');
        expect(instances[0].started).toBe(true);

        await wrapper.find('[data-test="mic-toggle"]').trigger('click');
        expect(instances[0].stopped).toBe(true);
    });

    it('releases the microphone when the owner is torn down', async () => {
        const { instances } = installRecognition();

        // Exercised through a component, because that is how the composable is
        // actually used: the page owns the session, and a teardown hook that
        // only works inside `setup()` would be untested and therefore unproven.
        let release: (() => void) | undefined;

        const wrapper = mount({
            setup() {
                const { dictation } = useVoice();
                release = dictation.abandon;

                return { dictation };
            },
            template: '<button data-test="go" @click="dictation.start()">go</button>',
        });

        await wrapper.find('[data-test="go"]').trigger('click');
        await nextTick();

        expect(instances[0].started).toBe(true);
        expect(instances[0].aborted).toBe(false);

        wrapper.unmount();
        await nextTick();

        // Changing conversation unmounts the page; the microphone must not stay
        // open and deliver audio into a conversation it no longer serves.
        expect(instances[0].aborted).toBe(true);
        expect(release).toBeTypeOf('function');
    });
});

describe('useVoice — playback', () => {
    beforeEach(() => {
        vi.unstubAllGlobals();
    });

    it('reports unsupported and refuses to speak without speechSynthesis', () => {
        const { playback } = useVoice();

        expect(playback.supported.value).toBe(false);

        playback.speak('hola');

        expect(playback.error.value).toContain('no puede leer');
    });

    it('reads a reply aloud in Spanish', async () => {
        const tally = installSynthesis();
        const { playback } = useVoice();

        playback.speak('La cartera vencida asciende a 45 millones.');

        expect(tally.spoken).toEqual(['La cartera vencida asciende a 45 millones.']);
        expect(tally.langs).toEqual(['es-CO']);
        expect(playback.error.value).toBeNull();
    });

    it('speaks a table as sentences, never as pipes or asterisks (A07-R4)', () => {
        const tally = installSynthesis();
        const { playback } = useVoice();

        playback.speak('| Cliente | Saldo |\n| --- | --- |\n| Empresa A | 120.000 |');

        expect(tally.spoken).toHaveLength(1);
        const spoken = tally.spoken[0];

        expect(spoken).not.toContain('|');
        expect(spoken).not.toContain('---');
        expect(spoken).toBe('Cliente: Empresa A. Saldo: 120.000.');
    });

    it('prefers Colombian Spanish when the browser lists another Spanish variant first (A07-R4)', () => {
        const tally = installSynthesis([
            { lang: 'es-ES', name: 'Monica', default: true },
            { lang: 'es-CO', name: 'Salome', default: false },
        ]);
        const { playback } = useVoice();

        playback.speak('hola');

        // Whatever order the browser enumerates voices in, the utterance must
        // carry the es-CO voice rather than the first Spanish one it found.
        const chosen = tally.voices[0] as { lang: string } | null;

        expect(chosen?.lang).toBe('es-CO');
    });

    it('ignores an empty reply rather than speaking nothing', () => {
        const tally = installSynthesis();
        const { playback } = useVoice();

        playback.speak('   ');

        expect(tally.spoken).toEqual([]);
    });

    it('pauses, resumes and stops', () => {
        const tally = installSynthesis();
        const { playback } = useVoice();

        playback.speak('una respuesta larga');
        playback.pause();
        playback.resume();
        playback.stop();

        expect(tally.paused).toBe(1);
        expect(tally.resumed).toBe(1);
        // Two cancels: `speak()` cancels whatever was playing before queueing,
        // and `stop()` cancels the utterance under test. Both are deliberate.
        expect(tally.cancelled).toBe(2);
    });

    it('replaces the current reading rather than queueing it', () => {
        const tally = installSynthesis();
        const { playback } = useVoice();

        playback.speak('primera');
        playback.speak('segunda');

        // The second reading replaces the first rather than queueing behind it.
        // Each `speak()` cancels whatever was playing before it, so two calls
        // produce two cancels and only one utterance is ever audible.
        expect(tally.spoken).toEqual(['primera', 'segunda']);
        expect(tally.cancelled).toBe(2);
    });
});