<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * The parser could not read the file, and the import becomes a controlled state.
 *
 * ## §4.2: a parser failure is never a stack trace
 *
 * Every failure here carries a code the API returns as 422 and a sentence the interface
 * shows. None carries a path, a stack or the reader's own message: an OpenXML failure is
 * usually a zip or XML error whose text names the file and sometimes a line inside it, and
 * §4.3's rule about not putting things in exception messages is about this moment as much as
 * about credentials.
 *
 * The operator's next move is different for each code — a `.xlsm` needs saving as `.xlsx`,
 * an empty file needs re-exporting — so the codes are worth distinguishing rather than
 * collapsing into `parse_failed`.
 */
final class WorkbookParseFailed extends \RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }

    /** Not an OpenXML container at all, or one whose central directory is unreadable. */
    public static function unreadableContainer(): self
    {
        return new self(
            'No se pudo leer el libro. El archivo puede estar dañado o no ser un .xlsx válido.',
            'unreadable_container',
        );
    }

    /** A readable file with no month-shaped sheet in it. */
    public static function noReadableSheet(): self
    {
        return new self(
            'El libro no tiene ninguna hoja con un nombre de mes y año, por ejemplo «ENERO 2026».',
            'no_readable_sheet',
        );
    }

    /** Every sheet was read and no row in any of them looked like a person. */
    public static function noRowsFound(): self
    {
        return new self(
            'El libro tiene hojas reconocibles pero no se encontró ninguna fila de persona.',
            'no_rows_found',
        );
    }

    public function userMessage(): string
    {
        return $this->getMessage();
    }
}
