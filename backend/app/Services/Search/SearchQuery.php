<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * Immutable free-text search input.
 *
 * Kept separate from the filter DTO: filters are structured predicates, search
 * is a relevance concern, and only the latter changes when the engine changes.
 */
final readonly class SearchQuery
{
    public function __construct(
        public string $term = '',
        public bool $semantic = false,
    ) {}

    public static function fromRequest(?string $term, bool $semantic = false): self
    {
        return new self(trim((string) $term), $semantic);
    }

    public function isEmpty(): bool
    {
        return $this->term === '';
    }

    /**
     * Boolean-mode safe form of the term.
     *
     * MySQL boolean mode treats + - > < ( ) ~ * " @ as operators; passing raw
     * user input through would let a stray character change the query's meaning
     * or throw a syntax error.
     */
    public function toBooleanMode(): string
    {
        $cleaned = preg_replace('/[+\-><\(\)~*"@]+/', ' ', $this->term) ?? '';
        $words = array_values(array_filter(preg_split('/\s+/', $cleaned) ?: []));

        if ($words === []) {
            return '';
        }

        // Require every word, and prefix-match the last so typeahead works.
        $last = array_pop($words);
        $required = array_map(static fn (string $w) => '+'.$w, $words);
        $required[] = '+'.$last.'*';

        return implode(' ', $required);
    }
}
