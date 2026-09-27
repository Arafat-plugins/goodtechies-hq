<?php

namespace App\Http\Resources;

use App\Support\SearchHit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One search hit, on the wire.
 *
 * ## Why this is thin, and why it still exists
 *
 * Every other serializer in this application exists to decide which fields of a MODEL leave
 * the server — `ProjectResource` holds `internal_notes` behind `viewCommercial`,
 * `AccountantProjectResource` emits four keys and no fifth. This one holds nothing back,
 * because by the time it runs there is nothing left to hold back: `SearchService` has already
 * read the row, taken the columns `SearchableType` declares readable, and thrown the model
 * away. A `SearchHit` has six properties and a resource cannot reach a seventh.
 *
 * That is the point of the split, and it is the argument for keeping the resource rather than
 * returning arrays straight out of the service. The rule *"a snippet never contains a
 * restricted field"* is decided in exactly one place (`SearchableType::snippetColumns()`) and
 * enforced in exactly one place (`SearchService::excerptFrom()`, which takes a TYPE and never
 * a column name). If this class built the snippet — or took the model and cut one — that rule
 * would have two homes and the repo's own line about a rule spelled twice would apply.
 *
 * What it is for instead is the WIRE SHAPE: one place that defines the exact key set, so
 * `SearchEndpointTest` can assert it exactly the way `AccountantProjectEndpointTest` asserts
 * its four. A check for three field names passes the day somebody adds a fourth.
 *
 * @property-read SearchHit $resource
 */
class SearchResultResource extends JsonResource
{
    /**
     * Five keys. The palette needs a stable identity for `:key`, a group to render under, a
     * name to show, a line of context, and somewhere to go.
     *
     * `rank` is deliberately NOT here. It is an implementation detail of the ordering, the
     * array arrives already sorted by it, and a score on the wire is a number somebody will
     * eventually render — or, worse, compare across types, where `ts_rank` values are not
     * comparable at all.
     *
     * @return array{type: string, id: int, label: string, snippet: string|null, href: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->resource->type->value,
            'id' => $this->resource->id,
            'label' => $this->resource->label,

            // Null rather than an empty string when the type has no snippet columns — a
            // client, a person, a file. "There is nothing more to say about this row" and
            // "the excerpt came out blank" are different facts, and the palette renders the
            // first as one line and the second as a broken second one.
            'snippet' => $this->resource->snippet,

            // Already resolved for THIS viewer's surface by the service. The palette never
            // builds an address, which is why it cannot build one into a shell the reader
            // would meet a 403 in.
            'href' => $this->resource->href,
        ];
    }
}
