<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * A workbook this module refuses, and why.
 *
 * ## Why this carries a code and not a message
 *
 * The upload endpoint answers 422 with `errors.file` and this code, and the interface
 * branches on it: a macro-enabled workbook gets "este archivo tiene macros" and a
 * zip-bomb-looking one gets "es demasiado grande al descomprimirse". A single
 * "archivo no válido" for all of them teaches an operator nothing and gets the same
 * refusal next time.
 *
 * ## Why it never carries the path
 *
 * §4.3 and the project's security rules both forbid the server's filesystem reaching a
 * browser through an error. An exception message is the easiest route there is, and a
 * rejected upload is exactly the path where a path would be tempting to include for
 * debugging. It is deliberately absent, and the audit trail has the import row instead.
 *
 * ## The `count` in two of them is not a detail
 *
 * "Too many entries" and "too large when expanded" both carry the observed figure. A
 * reviewer looking at a rejected upload needs to know whether it was twice over the limit
 * or a thousand times over, because those are different problems: one is a bad export and
 * the other is not an export.
 */
final class WorkbookRejected extends \RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function extension(string $received): self
    {
        return new self(
            sprintf('Sólo se aceptan archivos .xlsx. El archivo recibido es «.%s».', $received),
            'unsupported_extension',
        );
    }

    public static function unreadable(): self
    {
        // Deliberately no path. See the class docblock.
        return new self('El archivo no pudo leerse.', 'unreadable_file');
    }

    public static function empty(): self
    {
        return new self('El archivo está vacío.', 'empty_file');
    }

    public static function tooLarge(int $bytes, int $limit): self
    {
        return new self(
            sprintf('El archivo pesa %s y el máximo es %s.', self::megabytes($bytes), self::megabytes($limit)),
            'file_too_large',
        );
    }

    public static function notAnArchive(int|string $code): self
    {
        return new self(
            'El archivo no es un libro de Excel válido. Un libro es un contenedor OpenXML, '
                .'y lo que se recibió no lo es.',
            'not_an_openxml_container',
        );
    }

    public static function tooManyEntries(int $entries, int $limit): self
    {
        return new self(
            sprintf('El libro declara %d entradas y el máximo es %d.', $entries, $limit),
            'too_many_zip_entries',
        );
    }

    public static function tooLargeWhenExpanded(int $bytes, int $limit): self
    {
        return new self(
            sprintf(
                'El libro declara %s al descomprimirse y el máximo es %s.',
                self::megabytes($bytes),
                self::megabytes($limit),
            ),
            'expansion_too_large',
        );
    }

    public static function unsafeEntryName(): self
    {
        return new self(
            'El libro contiene una entrada con una ruta que sale de su propia carpeta.',
            'unsafe_entry_name',
        );
    }

    public static function forbiddenEntry(string $entry): self
    {
        return new self(
            sprintf('El libro no puede contener «%s». Un .xlsx no lleva macros.', $entry),
            'macro_enabled_workbook',
        );
    }

    public static function corruptEntry(): self
    {
        return new self('El libro tiene una entrada que no se pudo leer.', 'corrupt_entry');
    }

    public static function notAWorkbook(): self
    {
        return new self(
            'El archivo es un contenedor válido pero no contiene un libro de Excel.',
            'not_a_workbook',
        );
    }

    public static function externalReference(string $kind): self
    {
        return new self(
            'El libro referencia contenido externo y esta importación no lo resuelve.',
            'external_reference',
        );
    }

    /** The sentence the interface shows, and nothing about the server's disk. */
    public function userMessage(): string
    {
        return $this->getMessage();
    }

    private static function megabytes(int $bytes): string
    {
        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
