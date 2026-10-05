<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | A04 — importing a legacy monthly workbook
    |--------------------------------------------------------------------------
    |
    | The importer is a **specific reader for a known file shape**, not a configurable
    | column mapper. §2 of the specification is explicit that the reason is auditability:
    | a mapping that says "the column I guessed is the risk" is a guess the operator
    | cannot review, and a mapping stored in configuration is a mapping somebody edits
    | instead of a decision somebody makes. The profile is named on every import row so
    | the reader that ran is recorded with the batch.
    |
    */

    'profile' => env('IMPORT_PROFILE', 'blinden_legacy_monthly_v1'),

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | 10 MiB. The delivered workbook is about 749 KiB, so the limit is roughly an
    | order of magnitude above the real thing and well below anything that would make
    | the zip-bomb checks the real defence rather than the first line of it. Those
    | checks bound what a small file can expand to; this bounds what a large file
    | may be at all.
    |
    | The file is written to the **private** disk under a generated UUID directory.
    | `public` is never used and no URL is ever produced, because the workbook is
    | production-like personal data and a link to it is a disclosure.
    |
    */

    'disk' => env('IMPORT_DISK', 'local'),

    'max_bytes' => (int) env('IMPORT_MAX_BYTES', 10 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | The OpenXML container, checked before anything parses it
    |--------------------------------------------------------------------------
    |
    | `.xlsx` is a ZIP archive, and a ZIP archive can be a bomb: thousands of entries,
    | paths that escape the extraction root, a total uncompressed size far larger than
    | the file on disk, or a macro-enabled workbook renamed with a `.xlsx` extension.
    | A PHP application that opens an uploaded archive without these bounds is the
    | standard zip-bomb target, and this one processes payroll data, so it is worth
    | more than usual.
    |
    | `max_entries` is generous for a workbook with ten sheets and blocks; the
    | workbook this module was built for has 101 company blocks. `max_uncompressed_bytes`
    | is the real ceiling and it is a multiple of the upload limit because a spreadsheet
    | of shared strings compresses well below its expanded size.
    |
    */

    'openxml' => [
        'max_entries' => (int) env('IMPORT_MAX_ENTRIES', 2_000),
        'max_uncompressed_bytes' => (int) env('IMPORT_MAX_UNCOMPRESSED_BYTES', 64 * 1024 * 1024),

        /**
         * Entries that must not exist in an `.xlsx`.
         *
         * `vbaProject.bin` is a macro-enabled workbook, whatever it is called. A payroll
         * file has no macros and one that does is not the file this module reads, so the
         * refusal is unconditional rather than a warning.
         */
        'forbidden_entries' => [
            'vbaProject.bin',
            'vbaData.xml',
        ],

        /**
         * Relationships that would make the reader fetch something.
         *
         * External links are refused rather than ignored: a workbook that points at
         * `file:///etc/passwd` or a UNC share is asking the reader to leave the disk, and
         * a reader that "helpfully" resolves it is a server-side request forgery with a
         * spreadsheet attached. OpenSpout does not resolve them; this states it so a future
         * change that does has to confront the decision.
         */
        'forbidden_relationships' => [
            'externalLink',
            'externalReference',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reading the workbook
    |--------------------------------------------------------------------------
    */

    'read' => [
        /**
         * The last column the profile reads.
         *
         * The delivered file's `used range` runs to `XAQ` on the first two sheets because
         * of formatting, not data: every real row stops at `R`. Reading to the inflated
         * edge is how a parser ends up allocating a row of 16 000 empty cells per person,
         * and it is the reason this is a constant rather than a computed bound.
         */
        'last_column' => 'R',

        /** Rows a single sheet may contribute. A guard, not a business rule. */
        'max_rows_per_sheet' => (int) env('IMPORT_MAX_ROWS_PER_SHEET', 20_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | §16: three small jobs, not one per row. 2.560 rows through the parse is one
    | `ParseLegacyImport`, one `BuildLegacyImportPlan` and one `ApplyLegacyImport`. The
    | queue connection is the project's existing one; nothing here requires a new
    | infrastructure service, which is what A01 was built to avoid.
    |
    */

    'queue' => [
        /**
         * `null` means "whatever the application uses".
         *
         * The previous default was a hardcoded `redis`, and the jobs read it into their
         * constructor — so importing in a test suite that sets `QUEUE_CONNECTION=sync` silently
         * pushed the work onto Redis instead of running it inline, and every test that expected
         * staging to have happened by the time the endpoint returned saw an import still sitting
         * in `queued`. §16 asks the import jobs to use the existing Redis infrastructure, and a
         * deployment sets `IMPORT_QUEUE_CONNECTION=redis` to get it; a suite that wants inline
         * execution gets it without having to know this file exists.
         */
        'connection' => env('IMPORT_QUEUE_CONNECTION'),

        /** The queue *name*, so imports do not compete with A03's generation backlog. */
        'queue' => env('IMPORT_QUEUE', 'imports'),

        /** §16: "deja suficiente información para reintentar". */
        'tries' => (int) env('IMPORT_TRIES', 3),
        'timeout' => (int) env('IMPORT_TIMEOUT', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | What the importer is allowed to change
    |--------------------------------------------------------------------------
    |
    | §10 and §23, stated in configuration so the refusal is readable from one place
    | rather than spread across the plan builder.
    |
    | A04 writes masters, historical relationships, affiliations and monthly amounts.
    | It does **not** write obligations, periods, payments or allocations, and
    | `PLAN_WRITE_TARGETS` is the list the plan builder checks itself against: a
    | spreadsheet column headed "PLANILLA" is a reference, not money, and a product that
    | silently created a payment from it would be wrong in the direction nobody notices
    | until a balance is wrong.
    |
    */

    'plan_write_targets' => [
        'client',
        'company',
        'social_entity',
        'relationship',
        'affiliation',
        'rate',
    ],

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    |
    | §4.3. The delivered workbook has 23 cells whose text looks like a credential —
    | `CLAVE`, `PASSWORD`, `CONTRASEÑA`, a bare `USUARIO` with a value beside it, `TOKEN`,
    | `SECRET`. They are company titles and notes, so they arrive in the same cells as
    | the business facts and cannot be avoided by not reading them.
    |
    | The original stays on the private disk as the raw evidence. Everything the database
    | and the interface see is rewritten, and an issue names the pattern and the position
    | and never the value.
    |
    */

    'redaction' => [
        'placeholder' => '[REDACTED]',

        /**
         * Words that mark a value as a credential.
         *
         * Matched case- and accent-insensitively against the label before the separator,
         * because the sources write `CLAVE:`, `Clave =`, `PASSWORD:` and `Contraseña:` and
         * a rule that only recognised one spelling would miss the rest.
         */
        'markers' => [
            'CLAVE',
            'PASSWORD',
            'PASSWD',
            'CONTRASENA',
            'CONTRASEÑA',
            'TOKEN',
            'SECRET',
            'SECRETO',
            'APIKEY',
            'API KEY',
        ],

        /**
         * Labels whose *following* token is the secret.
         *
         * `USUARIO` is the one that cannot be handled as a keyword-and-value pair, because
         * in this source it appears as `USUARIO Juan.Perez` with no separator at all. The
         * label alone is not a secret, so only what follows it is taken.
         */
        'label_then_value' => [
            'USUARIO',
            'USER',
            'USERNAME',
            'LOGIN',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Withdrawing a name
    |--------------------------------------------------------------------------
    |
    | The real file holds one NIT that appears twice with two visually similar
    | numbers, and a parser that merged them by name would attach one company's payroll
    | to another. §7.2 makes it a blocker instead, and this is the list the check reads.
    | Empty by default: the conflict is discovered, not predicted.
    |
    */

    'known_company_identity_conflicts' => [
        // Filled in from the observed file when a reviewer has seen it. Left empty so the
        // rule stays general: what makes the conflict a blocker is that one name carries
        // two NITs, not that the NITs are in a list somewhere.
    ],

];
