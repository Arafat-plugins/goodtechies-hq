<?php

use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\Income;
use App\Models\User;
use App\Support\FinanceCategoryKind;
use App\Support\Permission as PermissionKey;
use App\Support\RoleName;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| IncomePolicy · ExpensePolicy · FinanceCategoryPolicy
|--------------------------------------------------------------------------
|
| Master prompt Part C §1:
|
|   | View finance (income/expense) | ✅ ADMIN | ❌ | ❌ | ❌ | ✅ ACCOUNTANT |
|   | Manage finance                | ✅ ADMIN | ❌ | ❌ | ❌ | ✅ ACCOUNTANT |
|
| and Phase 8's own security line: "Employees/Remote get 403 on every
| finance route".
|
| The last test in this file is the one that matters most. Every other test
| here checks an answer; that one checks WHERE the answer comes from — a key,
| never a role name — which is what makes a future bookkeeper role a seeder
| change rather than an edit to three policies.
|
*/

/** Role → whether it may read the books, write them, and edit the category list. */
const FINANCE_POLICY_MATRIX = [
    'admin' => ['view' => true, 'manage' => true, 'categories' => true],
    'accountant' => ['view' => true, 'manage' => true, 'categories' => false],
    'employee' => ['view' => false, 'manage' => false, 'categories' => false],
    'remote' => ['view' => false, 'manage' => false, 'categories' => false],
];

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->employee = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->remote = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    $this->income = Income::factory()->recordedBy($this->accountant)->create();
    $this->expense = Expense::factory()->recordedBy($this->accountant)->create();
    $this->category = FinanceCategory::query()->ofKind(FinanceCategoryKind::Income)->firstOrFail();
});

it('answers viewAny and view on both sides per the matrix', function (string $role) {
    $expected = FINANCE_POLICY_MATRIX[$role]['view'];
    $gate = Gate::forUser($this->{$role});

    expect($gate->allows('viewAny', Income::class))->toBe($expected)
        ->and($gate->allows('view', $this->income))->toBe($expected)
        ->and($gate->allows('viewAny', Expense::class))->toBe($expected)
        ->and($gate->allows('view', $this->expense))->toBe($expected)
        // The category list is READ with finance.view, so the Accountant's pickers work.
        ->and($gate->allows('viewAny', FinanceCategory::class))->toBe($expected);
})->with(array_keys(FINANCE_POLICY_MATRIX))->group('phase8', 'finance');

it('answers create, update and delete on both sides per the matrix', function (string $role) {
    $expected = FINANCE_POLICY_MATRIX[$role]['manage'];
    $gate = Gate::forUser($this->{$role});

    expect($gate->allows('create', Income::class))->toBe($expected)
        ->and($gate->allows('update', $this->income))->toBe($expected)
        ->and($gate->allows('delete', $this->income))->toBe($expected)
        ->and($gate->allows('create', Expense::class))->toBe($expected)
        ->and($gate->allows('update', $this->expense))->toBe($expected)
        ->and($gate->allows('delete', $this->expense))->toBe($expected);
})->with(array_keys(FINANCE_POLICY_MATRIX))->group('phase8', 'finance');

it('reserves changing the category list for the admin, per Part D §13', function (string $role) {
    $expected = FINANCE_POLICY_MATRIX[$role]['categories'];
    $gate = Gate::forUser($this->{$role});

    // "Categories (seeded, Admin-editable)". Spelled `settings.manage`, which is the matrix's
    // own Admin-only key — not a new key, and not a role named in a policy. See
    // FinanceCategoryPolicy for the two rejected alternatives.
    expect($gate->allows('create', FinanceCategory::class))->toBe($expected)
        ->and($gate->allows('update', $this->category))->toBe($expected)
        ->and($gate->allows('delete', $this->category))->toBe($expected);
})->with(array_keys(FINANCE_POLICY_MATRIX))->group('phase8', 'finance');

it('refuses a deactivated user who still holds both keys', function () {
    $this->accountant->employee->update(['status' => 'inactive']);
    $this->accountant->update(['status' => 'inactive']);

    $gate = Gate::forUser($this->accountant->fresh());

    expect($gate->allows('viewAny', Income::class))->toBeFalse()
        ->and($gate->allows('create', Expense::class))->toBeFalse();
})->group('phase8', 'finance');

it('grants the two finance keys to exactly ADMIN and ACCOUNTANT in the seeded matrix', function () {
    $holders = [];

    foreach (RoleName::cases() as $role) {
        $keys = RolePermissionSeeder::MATRIX[$role->value];

        if (in_array(PermissionKey::FinanceView->value, $keys, true)
            || in_array(PermissionKey::FinanceManage->value, $keys, true)) {
            $holders[] = $role->value;
        }
    }

    expect($holders)->toEqualCanonicalizing([RoleName::ADMIN->value, RoleName::ACCOUNTANT->value]);
})->group('phase8', 'finance');

it('names no role anywhere in the three finance policies', function () {
    // The load-bearing test of this file. `meetings.use` and `messages.use` spell "the
    // Accountant has no meetings" and "no messaging routes" without naming them; these three
    // policies spell "the Employee has no finance" the same way. The consequence is the point:
    // a future bookkeeper role gets every finance screen by being granted the keys in
    // RolePermissionSeeder, with no edit to any of these files.
    //
    // Contrast MeetingPolicy, which DOES name ADMIN — because a meeting has an owner and an
    // Admin override on somebody else's record is a scope. A finance record has no owner.
    foreach (['IncomePolicy', 'ExpensePolicy', 'FinanceCategoryPolicy'] as $policy) {
        $source = file_get_contents(app_path("Policies/{$policy}.php"));

        // The doc blocks discuss roles by name, deliberately; the CODE must not branch on one.
        $code = preg_replace('#/\*.*?\*/#s', '', $source);

        expect($code)->not->toContain('RoleName')
            ->and($code)->not->toContain('hasRole');
    }
})->group('phase8', 'finance');
