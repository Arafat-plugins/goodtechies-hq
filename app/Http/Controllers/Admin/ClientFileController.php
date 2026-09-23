<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManagesFiles;
use App\Http\Controllers\Controller;
use App\Http\Requests\File\StoreFileRequest;
use App\Models\Client;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The client detail page's Files tab (spec §28) — the same FileService again.
 *
 * Contracts and briefs are commercial documents, so reaching them at all takes
 * ClientPolicy::view (`clients.view_full`) and attaching takes ClientPolicy::update
 * (`clients.edit`), which only an Admin holds. Both are checked against the client record, not
 * against the surface: the surface middleware is the outer door, the policy is the rule.
 */
class ClientFileController extends Controller
{
    use ManagesFiles;

    public function __construct(private readonly FileService $files) {}

    public function index(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('view', $client);

        return $this->fileIndex($request, $client);
    }

    public function store(StoreFileRequest $request, Client $client): RedirectResponse
    {
        Gate::authorize('view', $client);

        return $this->fileStore($request, $client, 'File added to the client.');
    }
}
