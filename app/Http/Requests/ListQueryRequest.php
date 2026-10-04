<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Validation\SafeSearch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Shared query-string rules for the paginated A02 list endpoints.
 *
 * Two things are decided once here rather than in every controller:
 *
 *  - the page sizes. The default is 25 and the maximum is 100, because a list of
 *    an unbounded size is a denial of service a user can trigger by accident.
 *    The allowed values are a fixed set, not a range, so the interface can offer
 *    exactly what the server accepts.
 *
 *  - how a search term is treated. A user typing `%` or `_` is typing a
 *    character, not writing a pattern, so the wildcards are escaped. Without
 *    that, a search for "100%" would return everything, and a search for `_`
 *    would match any single character.
 */
abstract class ListQueryRequest extends FormRequest
{
    /**
     * Page sizes the interface may offer.
     *
     * @var list<int>
     */
    public const PAGE_SIZES = [25, 50, 100];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:'.implode(',', self::PAGE_SIZES)],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', 'string', 'in:active,inactive'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'per_page.in' => 'El número de registros por página debe ser 25, 50 o 100.',
            'per_page.integer' => 'El número de registros por página debe ser un número.',
            'search.max' => 'La búsqueda no puede superar los 120 caracteres.',
            'status.in' => 'El estado debe ser "active" o "inactive".',
        ];
    }

    /**
     * The requested page size, already bounded.
     */
    public function perPage(): int
    {
        return (int) $this->validated('per_page', 25);
    }

    /**
     * The search term as a LIKE pattern, with the wildcards a user typed
     * appended so that "juan" also finds "juancho".
     *
     * The folding and the escaping are `SafeSearch`'s, so that every searchable screen
     * behaves identically. The comparison is written by `SafeSearch::match()`, which folds
     * **both** sides in SQL as `lower(unaccent(...))`, and that is what actually makes the
     * search case- and accent-insensitive — a needle folded only here would miss `Única`,
     * because this database's `C` collation cannot fold a non-ASCII capital.
     *
     * `SafeSearch::likeNeedle()` also lowercases. That is redundant with the SQL and harmless.
     *
     * There is deliberately no escaping helper here any more. Two escaping paths in a
     * codebase means one of them is applied twice somewhere, and "escaped, then escaped
     * again" fails by matching nothing rather than by raising.
     *
     * @see SafeSearch::match()
     */
    public function searchPattern(): ?string
    {
        // The **raw** term, not a pre-escaped one. `SafeSearch::likeNeedle()` escapes, so
        // escaping here as well would escape twice and `a_b` would arrive at the database as
        // `a\\_b` — a literal backslash followed by any character, which matches nothing.
        // That is not a hypothetical: it is exactly what this method did for one commit.
        $search = $this->validated('search');

        if (! is_string($search) || trim($search) === '') {
            return null;
        }

        return SafeSearch::likeNeedle($search);
    }

    /**
     * The requested status filter, if any.
     */
    public function statusFilter(): ?string
    {
        $status = $this->validated('status');

        return is_string($status) && $status !== '' ? $status : null;
    }

    /**
     * A trimmed, non-empty string parameter, or null.
     */
    protected function trimmedQuery(string $key, int $max = 120): ?string
    {
        $value = $this->query($key);

        if (! is_string($value)) {
            return null;
        }

        $value = Str::limit(trim($value), $max, '');

        return $value === '' ? null : $value;
    }
}
