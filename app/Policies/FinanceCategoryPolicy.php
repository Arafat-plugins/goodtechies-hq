<?php

namespace App\Policies;

use App\Models\FinanceCategory;
use App\Models\User;
use App\Support\Permission;

/**
 * Who may read the category list, and who may change it (master prompt Part C §1; Part D §13).
 *
 * ## Reading and writing are different keys, and that is the interesting part of this file
 *
 * | ability                  | key              | who holds it today |
 * | ------------------------ | ---------------- | ------------------ |
 * | `viewAny` / `view`       | `finance.view`   | ADMIN, ACCOUNTANT  |
 * | `create`/`update`/`delete` | `settings.manage` | ADMIN            |
 *
 * **Reading is `finance.view`** because every income and expense form has a category picker and
 * the Accountant lives in those forms. A category list they could not read would make the
 * finance screens unusable for the only person whose job they are.
 *
 * **Writing is `settings.manage`**, and the reason is Part D §13's own words. Phase 8's screen
 * list calls this *"Categories (seeded, **Admin-editable**)"* — Admin, not Accountant — and
 * the permission matrix has no *manage finance categories* row at all. The two candidates were:
 *
 *   1. `finance.manage`, which the Accountant holds. Rejected: it would give the Accountant a
 *      power Part D explicitly reserves, and the difference is real rather than pedantic. The
 *      category list is the shape of the company's reporting — rename *Website* and every
 *      historic report reads differently; add a tenth expense category and the Admin's
 *      by-category dashboard grows a line nobody chose. That is configuration of the finance
 *      module, not bookkeeping inside it.
 *   2. A new key, `finance.manage_categories`. Rejected under Part C §1's rule that keys are
 *      *"defined once"* and that scopes never become new keys — and because a key held by
 *      exactly the role that already holds `settings.manage`, granted to nobody else, is that
 *      key with a longer name.
 *
 * So the capability is spelled with the key the matrix already has for *"Manage system
 * settings"*, which is ADMIN and nobody else — and, as everywhere in this application, **no
 * role is named in this file**. The audit trail agrees with the choice: a category change is
 * written as `AuditEvent::ConfigurationChanged`, which is Part C §4's own last line,
 * *"configuration changes"*.
 *
 * If the client says the Accountant should own the category list, this file changes in one
 * place and `RolePermissionSeeder` does not change at all. That question is in this slice's
 * report as an open one.
 */
class FinanceCategoryPolicy extends Policy
{
    /** Every picker on every finance form. */
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function view(User $user, FinanceCategory $category): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::SettingsManage);
    }

    /**
     * Renaming one, or reordering the list. **Not** moving it to the other side of the ledger:
     * that is refused for everybody by `FinanceService::updateCategory()` and, underneath it,
     * by `ON UPDATE RESTRICT` on the composite foreign key.
     */
    public function update(User $user, FinanceCategory $category): bool
    {
        return $this->allows($user, Permission::SettingsManage);
    }

    /**
     * Removing one. Refused by the database while anything is filed under it — the ability is
     * about the person, and `FinanceStateException::categoryInUse()` is about the category.
     */
    public function delete(User $user, FinanceCategory $category): bool
    {
        return $this->allows($user, Permission::SettingsManage);
    }
}
