<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Document workspace
    |--------------------------------------------------------------------------
    |
    | Everything about files a person gives to, or asks the assistant to
    | produce. The limits here are not arbitrary: each one corresponds to a
    | specific way an untrusted file can cost more than it is worth.
    |
    */

    'upload' => [
        /*
         | A ceiling on what one upload may be. Rejected before the bytes are
         | read into memory, not after, so an oversized file costs a check
         | rather than a process.
         */
        'max_kilobytes' => (int) env('AI_DOCUMENT_MAX_KB', 20 * 1024),

        /*
         | The formats accepted. Everything else is refused by name, which is
         | more useful than a generic "unsupported file".
         */
        'extensions' => ['pdf', 'docx', 'xlsx', 'csv', 'txt', 'md'],

        /*
         | How much text is taken out of one file. A document can inflate: a
         | spreadsheet may declare an enormous used range, and a file that
         | compresses well can carry far more text than its size suggests.
         */
        'max_characters_per_document' => 2_000_000,

        'max_spreadsheet_rows' => 5000,

        'max_chunks_per_document' => 2000,
    ],

    'retrieval' => [
        /*
         | The passages one question may bring back, and how much of each.
         |
         | Both are small on purpose. A document can be a whole payroll file,
         | and the answer to "¿cuánto se adeuda?" is a figure, not the file.
         */
        'max_passages' => 6,

        'passage_characters' => 1200,

        /*
         | How much of a document is read when the whole document is asked
         | about, rather than a question about it.
         */
        'max_whole_document_chars' => 40_000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Document conversion
    |--------------------------------------------------------------------------
    |
    | The isolated LibreOffice service. It is optional: a deployment that never
    | starts it has documents it can read and no documents it can convert, and
    | says so rather than offering a conversion that will fail.
    |
    */
    'converter' => [
        'enabled' => (bool) env('DOCUMENT_CONVERTER_ENABLED', false),

        'base_url' => env('DOCUMENT_CONVERTER_URL', 'http://converter:8080'),

        /*
         | The directory shared with the converter. A named volume mounted into
         | both containers, not a path inside the project: the two services
         | share one directory and nothing else.
         */
        'exchange_path' => env('DOCUMENT_CONVERTER_EXCHANGE', storage_path('app/document-work')),

        'unavailable_message' => 'La conversión de documentos no está disponible en esta instalación.',
    ],

];