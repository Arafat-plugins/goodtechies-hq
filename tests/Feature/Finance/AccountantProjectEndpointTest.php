<?php

use App\Models\Income;
use App\Models\Project;
use App\Models\User;
use App\Support\FinanceCategoryKind;
use Database\Factories\IncomeFactory;

/*
|--------------------------------------------------------------------------
| GET /accountant/projects — the finance-only project endpoint
|--------------------------------------------------------------------------
|
| Master prompt Part D §13, and the sharpest privacy boundary in the
| application:
|
|   "the matrix says ❌ view projects and ❌ clients, but 🟡 view project
|    finance, read-only, linked to invoicing. Implemented as a dedicated
|    finance-only endpoint → id, project name, domain, project_finance
|    fields, read-only — no client name or contacts (AC5)."
|
| The first two tests are the whole point of the file and they are written
| the way tests/Feature/Team/TeamDirectoryTest.php writes the same rule: an
| EXACT key-set assertion, plus a recursive walk for names that must never
| appear at any depth. The exact-key assertion is the real test — a check
| for three field names passes the day somebody adds a fourth.
|
| Every constant and helper here is prefixed FINANCE_ / financeEndpoint*,
| because Pest declares both globally across the whole suite (AGENTS.md).
|
*/

/** Every key `AccountantProjectResource` sends at the top level, and the whole of it. */
const FINANCE_ENDPOINT_PROJECT_KEYS = ['id', 'name', 'domain', 'finance'];

/** Every key inside `finance` — exactly the six money columns of `project_finance`. */
const FINANCE_ENDPOINT_FINANCE_KEYS = [
    'price',
    'recurring_amount',
    'billing_frequency',
    'contract_value',
    'contract_terms',
    'profitability_snapshot',
];

/**
 * Names that must never appear in this payload at ANY depth.
 *
 * The exact-key assertions above are the real test; this is the second net, because a nested
 * object would satisfy a top-level key list and still carry a client underneath. The counts are
 * on the list deliberately: `tasks_count` is a fact about how busy a client's account is, and
 * the Accountant has ❌ on that entire column of the matrix.
 */
const FINANCE_ENDPOINT_FORBIDDEN_KEYS = [
    'client',
    'client_id',
    'client_name',
    'contact',
    'contacts',
    'contact_info',
    'email',
    'phone',
    'description',
    'internal_notes',
    'employee_notes',
    'notes',
    'status',
    'status_label',
    'priority',
    'deadline',
    'start_date',
    'project_type',
    'billing_type',
    'pm',
    'pm_id',
    'members',
    'member_count',
    'tasks',
    'tasks_count',
    'task_count',
    'open_tasks',
    'messages',
    'messages_count',
    'files',
    'meetings',
    'archived_at',
    'permissions',
    'project_id',
    'created_at',
    'updated_at',
];

/** @return array<int, array<string, mixed>> the endpoint's rows, as the Accountant */
function financeEndpointRows(User $accountant): array
{
    return test()->actingAs($accountant)->getJson('/accountant/projects')->assertOk()->json('data');
}

beforeEach(function () {
    $this->seed();

    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->employee = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->remote = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    $this->seoProject = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| The payload — the exact key set, and nothing beyond it
|--------------------------------------------------------------------------
*/

it('sends exactly four keys per project and not one more', function () {
    $rows = financeEndpointRows($this->accountant);

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect(array_keys($row))->toEqualCanonicalizing(FINANCE_ENDPOINT_PROJECT_KEYS);
    }
})->group('phase8', 'finance');

it('sends exactly the six project_finance fields inside finance, and no row metadata', function () {
    $rows = financeEndpointRows($this->accountant);

    $withFinance = array_values(array_filter($rows, fn (array $row): bool => $row['finance'] !== null));

    // DemoSeeder gives six of the seven projects a finance row; the internal one has none.
    expect($withFinance)->toHaveCount(6);

    foreach ($withFinance as $row) {
        expect(array_keys($row['finance']))->toEqualCanonicalizing(FINANCE_ENDPOINT_FINANCE_KEYS);
    }
})->group('phase8', 'finance');

it('carries no client, no contact and no operational field anywhere in the payload', function () {
    $rows = financeEndpointRows($this->accountant);

    $keys = [];
    array_walk_recursive($rows, function (mixed $value, string|int $key) use (&$keys): void {
        $keys[] = (string) $key;
    });

    foreach (FINANCE_ENDPOINT_FORBIDDEN_KEYS as $forbidden) {
        expect($keys)->not->toContain($forbidden);
    }
})->group('phase8', 'finance');

it('names no seeded client and no seeded contact anywhere in the serialized body', function () {
    // The key walk above catches a field somebody NAMED; this catches a client's name arriving
    // as the VALUE of an allowed key — `name` being set to the client rather than the project,
    // or a contact landing in `contract_terms`.
    $body = json_encode(financeEndpointRows($this->accountant), JSON_THROW_ON_ERROR);

    foreach (['Buffalo Modular Homes', 'Heat Gap Heating & Plumbing', 'APH St Albans', 'ABC Ltd'] as $client) {
        expect($body)->not->toContain($client);
    }

    foreach (['Karen Buffalo', 'Dave Heatgap', 'Priya Aph', 'Sam Abc'] as $contact) {
        expect($body)->not->toContain($contact);
    }
})->group('phase8', 'finance');

it('does give the accountant the domain, which is how a human tells two maintenance rows apart', function () {
    $rows = financeEndpointRows($this->accountant);

    $seo = collect($rows)->firstWhere('name', 'Buffalo Modular — SEO');

    // Part C §2 settles this: the domain is what stands IN PLACE OF the client name. It is one
    // of the four fields Part D §13 lists, and without it four "— Website Maintenance" rows are
    // indistinguishable on an invoice.
    expect($seo['domain'])->toBe('buffalomodular.com')
        ->and($seo['finance']['recurring_amount'])->not->toBeNull();
})->group('phase8', 'finance');

it('keeps the finance key present and null for a project with no finance row', function () {
    $internal = collect(financeEndpointRows($this->accountant))->firstWhere('name', 'GoodTechies HQ — Internal');

    // Not an absence: "this project is billed to nobody" is the honest answer, and the key set
    // must not change shape per row. A privacy absence is the key not existing at all.
    expect($internal)->toHaveKey('finance')
        ->and($internal['finance'])->toBeNull();
})->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| Who may call it
|--------------------------------------------------------------------------
*/

it('refuses the employee and the remote employee, who hold no projects.view_finance', function (string $user) {
    $this->actingAs($this->{$user})->getJson('/accountant/projects')->assertForbidden();
})->with(['employee', 'remote'])->group('phase8', 'finance');

it('sends a signed-out request to the login page rather than answering it', function () {
    $this->get('/accountant/projects')->assertRedirect('/login');
})->group('phase8', 'finance');

it('refuses the admin, who reaches project money through the ordinary project routes', function () {
    // Not a privacy rule — the Admin may see everything this endpoint sends and a great deal
    // more. It is `surface:accountant`: the three shells are separate, and an Admin reading the
    // Accountant's endpoint would be an Admin screen quietly depending on the Accountant's
    // route file. `ProjectResource` is the Admin's serializer for exactly this data.
    $this->actingAs($this->admin)->getJson('/accountant/projects')->assertForbidden();
})->group('phase8', 'finance');

/*
|--------------------------------------------------------------------------
| The endpoint exists BECAUSE the ordinary routes stay shut
|--------------------------------------------------------------------------
*/

it('still refuses the accountant every ordinary project route', function (string $method, string $route, bool $parameterised) {
    // The Phase 1 rule, restated here because this slice is the one that could have broken it.
    // tests/Feature/Privacy/ProjectPrivacyTest.php owns the full list and is untouched.
    $this->actingAs($this->accountant)
        ->call($method, $parameterised ? route($route, $this->seoProject) : route($route))
        ->assertForbidden();
})->with([
    ['GET', 'admin.projects.index', false],
    ['GET', 'admin.projects.show', true],
    ['PUT', 'admin.projects.update', true],
    ['PUT', 'admin.projects.finance.update', true],
    ['GET', 'employee.projects.index', false],
    ['GET', 'employee.projects.show', true],
])->group('phase8', 'finance');

it('still shows the accountant no project through Project::visibleTo, and lists them all here', function () {
    // The two facts that look contradictory and are the point of the slice. "Can this person
    // work on this project?" and "can this person invoice for it?" are different questions.
    expect(Project::query()->visibleTo($this->accountant)->count())->toBe(0)
        ->and(financeEndpointRows($this->accountant))->toHaveCount(Project::count());
})->group('phase8', 'finance');

it('lets the accountant link income to a project they cannot see any other way — by design', function () {
    // Stated as a test so that nobody "fixes" it later. Part D §13: the endpoint is "used by the
    // income form's project picker". If the picker could only offer projects the Accountant can
    // open through an ordinary route, it would be empty, and the finance-by-project report would
    // not exist.
    $income = Income::factory()
        ->recordedBy($this->accountant)
        ->forProject($this->seoProject)
        ->create(['category_id' => IncomeFactory::category(FinanceCategoryKind::Income, 'SEO')]);

    expect($income->project_id)->toBe($this->seoProject->id)
        ->and(Project::query()->visibleTo($this->accountant)->whereKey($this->seoProject->id)->exists())->toBeFalse();
})->group('phase8', 'finance');
