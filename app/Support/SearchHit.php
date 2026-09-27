<?php

namespace App\Support;

/**
 * One row of a search result, already projected.
 *
 * It exists so that `SearchService` — the only thing that knows what a viewer is allowed to
 * see — is also the only thing that decides what a hit CONTAINS, and `SearchResultResource`
 * decides only what a hit LOOKS LIKE on the wire. Splitting it that way is what stops the two
 * halves of "never put a restricted field in a snippet" from being written twice.
 *
 * Every field here is a string or an int, never a model: by the time a hit exists the row it
 * came from has been read, the allowed columns have been taken off it (see
 * {@see SearchableType::snippetColumns()}) and the rest has been dropped. A resource that
 * received the model instead could reach any column on it, which is the shape that leaks.
 */
final readonly class SearchHit
{
    /**
     * @param  SearchableType  $type  what kind of thing this is
     * @param  int  $id  the row's own id, in its own table
     * @param  string  $label  how the row names itself — the label column, or composed
     * @param  string|null  $snippet  one line cut from a SNIPPET column, or null when this
     *                                type has none to give. Never assembled from a column that
     *                                is not also searchable
     * @param  string  $href  a path on THIS viewer's own surface. Resolved on the server,
     *                        because an employee sent to `/admin/projects/12` meets a 403
     *                        dressed up as a link — the same reason
     *                        `MessageController::surfaceBase()` exists
     * @param  float  $rank  `ts_rank` within this type. Orders rows inside a group and is
     *                       never compared across types (see
     *                       {@see SearchableType::inDisplayOrder()})
     */
    public function __construct(
        public SearchableType $type,
        public int $id,
        public string $label,
        public ?string $snippet,
        public string $href,
        public float $rank = 0.0,
    ) {}

    /**
     * What a screen reader hears for this row.
     *
     * The kind comes first because the palette is one flat listbox: a group heading is
     * announced when focus enters the group and not again per row, so "Buffalo Modular — SEO"
     * on its own does not say whether it is a project, a file or a meeting.
     */
    public function accessibleName(): string
    {
        return $this->type->singular().': '.$this->label;
    }
}
