import { getCurrentInstance, onBeforeUnmount, ref, shallowRef } from 'vue';

import type { Ref } from 'vue';

/**
 * Voice input and output for the assistant.
 *
 * ## Two very different capabilities, one file
 *
 * Dictation is `SpeechRecognition`: the browser streams what the microphone
 * hears. Playback is `speechSynthesis`: the browser reads a reply aloud. They
 * share almost nothing - one captures, the other renders, they use different
 * vendor prefixes, and either can be missing while the other works - so they
 * are separate reactive blocks rather than one "voice" flag that claims to cover
 * both.
 *
 * ## What is promised and what is not
 *
 * `SpeechRecognition` is a Google Chrome extension of a W3C draft, exposed by
 * Chrome, Edge and Safari, and *not* by Firefox. It may send audio to a vendor
 * server to do recognition. It is not offline and not universal, and this
 * composable never implies otherwise: `dictation.supported` is the honest
 * answer, and when it is false the composer still works and the keyboard's own
 * dictation button still does the job.
 *
 * ## Nothing is recorded behind anyone's back
 *
 * `start()` is only ever called from an explicit click, and it stops on its own
 * after `autoStopMs` of silence so a forgotten microphone cannot stay open. The
 * recognised text lands in a ref the person can still edit before sending -
 * it is never appended straight into a submitted message.
 */

// The TypeScript DOM lib does not declare the prefixed or experimental shapes.
// Declared here rather than cast to `any` at each use site, so the rest of the
// file stays type-checked and the two shapes stay documented.
interface SpeechRecognitionAlternativeLike {
    transcript: string;
    confidence: number;
}

interface SpeechRecognitionResultLike {
    readonly length: number;
    isFinal: boolean;
    [index: number]: SpeechRecognitionAlternativeLike;
}

interface SpeechRecognitionResultListLike {
    readonly length: number;
    [index: number]: SpeechRecognitionResultLike;
}

interface SpeechRecognitionEventLike extends Event {
    resultIndex: number;
    results: SpeechRecognitionResultListLike;
}

interface SpeechRecognitionErrorEventLike extends Event {
    error: string;
}

interface SpeechRecognitionLike {
    lang: string;
    continuous: boolean;
    interimResults: boolean;
    maxAlternatives: number;
    start(): void;
    stop(): void;
    abort(): void;
    onresult: ((event: SpeechRecognitionEventLike) => void) | null;
    onerror: ((event: SpeechRecognitionErrorEventLike) => void) | null;
    onend: (() => void) | null;
    onstart: (() => void) | null;
}

type SpeechRecognitionConstructor = new () => SpeechRecognitionLike;

/** The prefixed and unprefixed constructors, whichever the browser exposes. */
function recognitionConstructor(): SpeechRecognitionConstructor | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const scope = window as unknown as {
        SpeechRecognition?: SpeechRecognitionConstructor;
        webkitSpeechRecognition?: SpeechRecognitionConstructor;
    };

    return scope.SpeechRecognition ?? scope.webkitSpeechRecognition ?? null;
}

export interface VoiceDictation {
    supported: Readonly<Ref<boolean>>;
    listening: Ref<boolean>;
    transcript: Ref<string>;
    interim: Ref<string>;
    error: Ref<string | null>;
    start(lang?: string): Promise<void>;
    stop(): void;
    reset(): void;
    /**
     * Throw the session away without finishing it.
     *
     * Used when the conversation changes: audio captured for a message that is
     * about to be discarded has to be released, not finished and delivered.
     */
    abandon(): void;
}

export interface VoicePlayback {
    supported: Ref<boolean>;
    speaking: Ref<boolean>;
    paused: Ref<boolean>;
    voices: Ref<SpeechSynthesisVoice[]>;
    error: Ref<string | null>;
    speak(text: string, lang?: string): void;
    pause(): void;
    resume(): void;
    stop(): void;
}

/** Wording shown to the person, rather than a raw vendor error code. */
const DICTATION_ERRORS: Record<string, string> = {
    'not-allowed': 'El navegador bloqueó el micrófono. Habilítalo en los permisos del sitio e inténtalo de nuevo.',
    'service-not-allowed': 'El navegador bloqueó el micrófono. Habilítalo en los permisos del sitio e inténtalo de nuevo.',
    'no-speech': 'No se detectó voz. Inténtalo de nuevo o usa el dictado del teclado.',
    'audio-capture': 'No se encontró un micrófono disponible.',
    network: 'El reconocimiento de voz necesita conexión. Se ha detenido.',
    aborted: 'Dictado detenido.',
};

export function useVoice(): { dictation: VoiceDictation; playback: VoicePlayback } {
    // ---------------------------------------------------------------- input
    const RecognitionCtor = recognitionConstructor();
    const dictationSupported = ref(RecognitionCtor !== null);

    const listening = ref(false);
    const transcript = ref('');
    const interim = ref('');
    const dictationError = ref<string | null>(null);

    // Held outside the reactive graph: a live `SpeechRecognition` is a handle to
    // a running microphone session, and making it reactive invites Vue to
    // proxy it. `shallowRef` keeps identity without wrapping the object.
    const recognition = shallowRef<SpeechRecognitionLike | null>(null);

    let silenceTimer: ReturnType<typeof setTimeout> | null = null;

    function clearSilenceTimer(): void {
        if (silenceTimer !== null) {
            clearTimeout(silenceTimer);
            silenceTimer = null;
        }
    }

    function reset(): void {
        transcript.value = '';
        interim.value = '';
        dictationError.value = null;
        clearSilenceTimer();
    }

    function stop(): void {
        clearSilenceTimer();

        const session = recognition.value;

        if (session) {
            // `stop()` asks the browser to finish cleanly and fire `onend`;
            // `abort()` throws away a partial result, which is what is wanted
            // when the person changes conversation mid-sentence.
            try {
                session.stop();
            } catch {
                // Already stopped. Nothing left to do.
            }
        }

        listening.value = false;
    }

    /**
     * Abandon the session entirely.
     *
     * Used when the conversation changes: the microphone belongs to the
     * conversation it was started in, and audio captured for a message that is
     * about to be discarded has to be thrown away rather than finished.
     */
    function abandon(): void {
        clearSilenceTimer();

        const session = recognition.value;

        if (session) {
            try {
                session.abort();
            } catch {
                // Already finished.
            }
        }

        recognition.value = null;
        listening.value = false;
        interim.value = '';
    }

    async function startDictation(lang = 'es-CO'): Promise<void> {
        if (!RecognitionCtor) {
            dictationError.value =
                'Este navegador no reconoce voz. Puedes usar el dictado del teclado en el campo de texto.';

            return;
        }

        if (listening.value) {
            return;
        }

        dictationError.value = null;
        interim.value = '';

        const session = new RecognitionCtor();
        recognition.value = session;

        session.lang = lang;
        session.continuous = false;
        session.interimResults = true;
        session.maxAlternatives = 1;

        session.onstart = () => {
            listening.value = true;
        };

        session.onresult = (event) => {
            let pending = '';

            for (let i = event.resultIndex; i < event.results.length; i += 1) {
                const result = event.results[i];

                if (result.isFinal) {
                    const final = result[0]?.transcript ?? '';
                    transcript.value = (transcript.value + ' ' + final).trim();
                } else {
                    pending += result[0]?.transcript ?? '';
                }
            }

            interim.value = pending;

            // A forgotten microphone that stays open for hours is worse than
            // one that closes itself, so silence ends the session.
            clearSilenceTimer();
            silenceTimer = setTimeout(() => stop(), 6000);
        };

        session.onerror = (event) => {
            const code = event.error;
            dictationError.value =
                DICTATION_ERRORS[code] ??
                `No fue posible reconocer la voz (${code}). Puedes escribir tu consulta.`;
        };

        session.onend = () => {
            listening.value = false;
            clearSilenceTimer();
            recognition.value = null;
        };

        try {
            session.start();
        } catch (error) {
            listening.value = false;
            recognition.value = null;
            dictationError.value =
                error instanceof Error && error.name === 'NotAllowedError'
                    ? DICTATION_ERRORS['not-allowed']
                    : 'No fue posible activar el micrófono.';
        }
    }

    // --------------------------------------------------------------- output
    const playbackSupported = ref(
        typeof window !== 'undefined' &&
        'speechSynthesis' in window &&
        typeof window.SpeechSynthesisUtterance === 'function',
    );

    const speaking = ref(false);
    // An utterance handed to the browser but not yet started.
    const queued = ref(false);
    const paused = ref(false);
    const playbackError = ref<string | null>(null);
    const voiceList = ref<SpeechSynthesisVoice[]>([]);

    function loadVoices(): void {
        if (!playbackSupported.value) {
            return;
        }

        voiceList.value = window.speechSynthesis.getVoices();
    }

    if (playbackSupported.value) {
        // `getVoices()` is populated asynchronously in most browsers and
        // returns an empty array on the first call, so the change event is the
        // only way to learn the list actually exists.
        loadVoices();
        window.speechSynthesis.addEventListener('voiceschanged', loadVoices);
    }

    function stopPlayback(): void {
        if (!playbackSupported.value) {
            return;
        }

        window.speechSynthesis.cancel();
        speaking.value = false;
        queued.value = false;
        paused.value = false;
    }

    function speak(text: string, lang = 'es-CO'): void {
        if (!playbackSupported.value) {
            playbackError.value = 'Este navegador no puede leer las respuestas en voz.';

            return;
        }

        const content = text.trim();

        if (content === '') {
            return;
        }

        // Reading a second reply over the first is not a queue, it is noise.
        stopPlayback();
        playbackError.value = null;

        const utterance = new SpeechSynthesisUtterance(content);
        utterance.lang = lang;

        // Spanish voice when the browser has one; otherwise the utterance is
        // still spoken, with the platform's own default, which is better than
        // refusing to read at all.
        const spanish = voiceList.value.find((voice) => voice.lang.toLowerCase().startsWith('es'));

        if (spanish) {
            utterance.voice = spanish;
        }

        utterance.onstart = () => {
            speaking.value = true;
            queued.value = false;
            paused.value = false;
        };

        utterance.onend = () => {
            speaking.value = false;
            queued.value = false;
            paused.value = false;
        };

        utterance.onerror = () => {
            speaking.value = false;
            queued.value = false;
            paused.value = false;
        };

        queued.value = true;
        window.speechSynthesis.speak(utterance);
    }

    /*
     | Pause and resume are guarded on `queued` rather than on `speaking`.
     |
     | `onstart` is asynchronous: the browser starts speaking some time after
     | `speak()` returns. Someone who presses pause straight away - which is
     | exactly what someone does when the reply is long and they have read the
     | first line - would be ignored, because `speaking` was not yet true. The
     | intent is tracked from the moment the utterance is handed over.
     */
    function pausePlayback(): void {
        if (!playbackSupported.value || paused.value || !queued.value) {
            return;
        }

        window.speechSynthesis.pause();
        paused.value = true;
    }

    function resumePlayback(): void {
        if (!playbackSupported.value || !paused.value) {
            return;
        }

        window.speechSynthesis.resume();
        paused.value = false;
    }

    // -------------------------------------------------------------- teardown
    //
    // Registered only when there is an active component instance to register
    // with. `onBeforeUnmount` warns when it is called outside `setup()`, and it
    // would be called outside `setup()` whenever the composable is used from a
    // plain script (a test, or a store action). The microphone would then stay
    // open with nothing left to close it, which is the one outcome that must
    // never happen: so `getCurrentInstance()` decides, and `abandon()` remains
    // callable directly for callers that need to end a session deliberately.
    if (getCurrentInstance()) {
        onBeforeUnmount(() => {
            abandon();
            stopPlayback();

            if (playbackSupported.value) {
                window.speechSynthesis.removeEventListener('voiceschanged', loadVoices);
            }
        });
    }

    return {
        dictation: {
            supported: dictationSupported,
            listening,
            transcript,
            interim,
            error: dictationError,
            start: startDictation,
            stop,
            reset,
            abandon,
        },
        playback: {
            supported: playbackSupported,
            speaking,
            paused,
            voices: voiceList,
            error: playbackError,
            speak,
            pause: pausePlayback,
            resume: resumePlayback,
            stop: stopPlayback,
        },
    };
}