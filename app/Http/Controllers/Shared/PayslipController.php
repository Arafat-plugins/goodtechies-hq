<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayrollItemResource;
use App\Models\PayrollItem;
use App\Services\PayrollService;
use App\Services\SettingsService;
use App\Support\PayrollStatus;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * **My Payslip** — this person's own pay, month by month (master prompt Part C §1's *"View own
 * payslip"* row, Part D §14's *"Each employee sees only their own payslip"*, Phase 9).
 *
 * Three endpoints and one rule underneath all three.
 *
 * ## One screen for every surface, the Accountant included
 *
 * Shared rather than one copy per shell, for the reason My Leave and Attendance are shared:
 * **whose pay it is belongs to the person, not to the shell they are looking at.** Part C §1
 * gives *View own payslip* to every role — ✅ in all five columns — and the note under that
 * matrix says it again: *"the Accountant shell therefore carries My Leave and My Payslip"*.
 * Three copies of these routes would have been three places for *"whose payslip is this"* to be
 * answered differently, and the Accountant's copy would have been the one nobody tested.
 * `Pages/Shared/Payslip/*.vue` pick their layout from `auth.user.surface`, so the Accountant
 * reads their payslip in the **Accountant** shell and the pages import nothing from
 * `Layouts/AdminLayout.vue` or `Pages/Admin/`.
 *
 * ## The scope is `PayrollService`'s, three times, and this class owns none of it
 *
 * Part B §3 rule 1 has three halves and slice 1 built all three:
 *
 *   - the listing **omits** other people's rows — `itemsFor()`;
 *   - a direct id is **404**, never 403 — `findItemFor()`;
 *   - and the attempt is **audit-logged** — `findItemFor()` again.
 *
 * So there is no `where('employee_id', …)` anywhere in this file and no `PayrollItemPolicy`
 * call before a lookup. A second copy of the scope is a second chance to get it wrong, and the
 * one place it would most likely have been forgotten is the PDF — which is exactly why
 * `pdf()` below resolves its id through the very same call `show()` does, on the line above the
 * renderer. `PayslipEndpointsTest` proves the 404 and the audit row on both.
 *
 * ## `admin_notes` is not on a payslip, and this class never sees it
 *
 * Decision 9-10: Part D §14 calls it *"the 'personal notes' column the Accountant never
 * receives"*, and slice 1 read Part C's deny-by-default the whole way — **the employee it is
 * about does not receive it either**. `PayrollItemResource` leaves the key *absent* for anybody
 * who is not an ADMIN, and everything a payslip prints comes from that resource, the browser
 * view and the PDF alike. Nothing here adds it back and no template names it.
 *
 * ## What counts as a *released* payslip: `PAID`, and only `PAID`
 *
 * Part D §14's last clause is the whole argument: *"**Paid** → payslip generated (browser print
 * view + PDF via dompdf) **and released**."* Release is tied to the money having moved, not to
 * the figures being settled, and the state machine says why that is the right line:
 *
 *   - `PayrollStatus::isOpenToAdmin()` keeps an item **editable right through `approved`**, so
 *     a document handed over at Approved can still change afterwards;
 *   - `locked → approved` is a legal move (the ADMIN lock reversal), so even `locked` can be
 *     re-opened by one person pressing one button;
 *   - `paid` is **terminal** — `TRANSITIONS['paid']` is empty — so it is the first and only
 *     status from which a figure cannot move again.
 *
 * A payslip is a receipt. Issuing one before the money left the bank, from a figure that is
 * still editable, produces exactly the complaint this screen exists to prevent: *"my payslip
 * said one thing and my account says another"*.
 *
 * **Earlier months are still shown, and labelled.** Hiding them would be worse, not safer: the
 * draft is the employee's own base and allowance, auto-created on the 1st from their own
 * salary, and a My Payslip screen that is empty for three weeks of every month teaches people
 * that it is broken. So every month this person has a line in is listed, a released one reads
 * as a payslip, and everything before it is marked **Provisional** in words — on the list, on
 * the page, on the print view and across the top of the PDF, whose filename says so too.
 */
class PayslipController extends Controller
{
    /**
     * How many of this person's months the list shows.
     *
     * Payroll is one row per person per month, so twelve is a year and a year is what somebody
     * came here for. It is a cap rather than a paginator because the honest alternative — a
     * pager under a list that is four rows long for everybody hired this year — is furniture,
     * and because the list is ordered newest-first: the twelve most recent months are the
     * twelve anybody asks about. A screen that needs the thirteenth is a Phase 10 report.
     */
    private const MONTHS = 12;

    public function __construct(
        private readonly PayrollService $payroll,
        private readonly SettingsService $settings,
    ) {}

    /**
     * My payslips, newest first.
     *
     * `itemsFor()` is the whole of the privacy rule's first half: somebody else's row is not in
     * the result, by the `WHERE` clause, and there is nothing here for Vue to filter. The
     * ordering is the service's (`payroll_periods.month` descending), so the first row is the
     * most recent month.
     *
     * ## …and then narrowed to *mine*, which is a different question
     *
     * `itemsFor()` answers *"what may this person see?"*. For an Employee that is already their
     * own row and nothing else. For an **ADMIN or an ACCOUNTANT it is the whole company**,
     * because both hold `payroll.view_others` and `PayrollItem::scopeVisibleTo()` returns
     * early for them — which is right for the payroll workbench and wrong for a screen called
     * *My Payslip*. Phase 9's test list says so in as many words: *"the Accountant's My Payslip
     * shows **only the Accountant's own item**"*.
     *
     * So the result is narrowed by `PayrollItem::belongsToEmployeeOf()` — the model's own
     * single statement of *"is this line mine?"*, the same predicate `findItemFor()` asks
     * before it writes an audit row. **There is no `where('employee_id', …)` in this file**,
     * and this narrowing is not a second copy of the privacy scope: it runs *after* it, it can
     * only ever remove rows, and it is about the screen's subject rather than about permission.
     * An Admin who wants somebody else's figures opens the payroll workbench, where they are
     * shown as a period of the company's pay and not as that person's payslip.
     *
     * Leave days are **not** computed here. The explanation of a deduction belongs on the
     * payslip that carries the deduction, and working it out per row would ask `LeaveService`
     * for a month of schedules and holidays twelve times to print a figure the list does not
     * show.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PayrollItem::class);

        $viewer = $request->user();

        $items = $this->payroll->itemsFor($viewer)
            ->filter(fn (PayrollItem $item): bool => $item->belongsToEmployeeOf($viewer))
            ->take(self::MONTHS);

        return Inertia::render('Shared/Payslip/Index', [
            'subject' => $this->subject($request),
            'currency' => (string) $this->settings->get('currency'),
            'items' => $items
                ->map(fn (PayrollItem $item): array => $this->row($request, $item))
                ->values()
                ->all(),
        ]);
    }

    /**
     * One payslip.
     *
     * The id goes through `findItemFor()` and nothing else: somebody else's is **404** and the
     * attempt is audit-logged, both inside that call. There is no branch in this method that
     * could produce a 403, which is the point — a 403 would confirm that the id exists.
     *
     * **`index()`'s own-only narrowing is deliberately not repeated here.** For everybody
     * without `payroll.view_others` the two are the same thing, and that is every role this
     * screen was built for. For an Admin or the Accountant, a hand-typed id renders a colleague's
     * figures on this template — which is not a disclosure, because both hold the permission and
     * the workbench prints the same numbers, and which is not worth a **fourth** shape of
     * refusal on top of Part C's three: a 404 on a record the requester is entitled to see would
     * mean something different here from everywhere else in this application.
     */
    public function show(Request $request, int $item): Response
    {
        $payslip = $this->payroll->findItemFor($request->user(), $item);

        return Inertia::render('Shared/Payslip/Show', [
            'subject' => $this->subject($request),
            'currency' => (string) $this->settings->get('currency'),
            'payslip' => $this->row($request, $payslip),
            'leave' => $this->leaveExplanation($payslip),
        ]);
    }

    /**
     * The same payslip as a PDF (Part D §14: *"payslip = browser print view + PDF via dompdf"*).
     *
     * **Server-rendered from a Blade template, never a screenshot of the Vue page.** dompdf
     * reads HTML and a little CSS; it does not run JavaScript, so a headless render of the
     * Inertia page would be a second renderer to keep in step with the first, and the figures
     * on the file somebody keeps for seven years would have been produced by a browser rather
     * than by the server that holds them. `resources/views/pdf/payslip.blade.php` prints the
     * same numbers this method hands the Vue page, out of the same resource.
     *
     * **The first line of this method is the scope**, and it is the same line `show()` opens
     * with. A PDF endpoint that resolved its id any other way would be the one hole in an
     * otherwise airtight rule; `PayslipEndpointsTest` asserts the 404 *and* the audit row here
     * separately from the HTML page, because "it works on the page" has never proved anything
     * about the download beside it.
     *
     * The filename names the person and the month — `Payslip-Yaseen-Karim-2026-09.pdf` — so a
     * folder of these is readable without opening them, and a provisional month says so in the
     * name as well as across the top of the page.
     */
    public function pdf(Request $request, int $item): HttpResponse
    {
        $payslip = $this->payroll->findItemFor($request->user(), $item);

        $row = $this->row($request, $payslip);

        $pdf = Pdf::loadView('pdf.payslip', [
            'payslip' => $row,
            'leave' => $this->leaveExplanation($payslip),
            'currency' => (string) $this->settings->get('currency'),
            'generatedOn' => now(config('app.timezone'))->format('j F Y'),
        ])
            ->setPaper('a4')
            // The template asks for DejaVu Sans rather than a PDF core font, because a core
            // font is Windows-1252 and a payslip has to be able to print somebody's name.
            // Embedding the whole face costs ~870 KB per download; subsetting embeds only the
            // glyphs this page actually uses and brings it back under 100 KB, which matters
            // for a file every employee downloads every month.
            ->setOption('isFontSubsettingEnabled', true);

        return $pdf->download($this->filename($payslip, $row['release']['released']));
    }

    /**
     * One row of the list, and the whole of the detail page: the resource, plus how to read it.
     *
     * **The figures come from `PayrollItemResource` and from nowhere else** — Part B §3 rule 1
     * names that class as the only way a payroll item leaves this server, `admin_notes` is
     * absent from it for everybody but an Admin, and `net_salary` is PostgreSQL's generated
     * column rather than anything added up here or in Vue.
     *
     * `release` sits **beside** the resource rather than inside it, for two reasons. The
     * resource is the payroll workbench's file and not this slice's to change; and more to the
     * point, whether a status counts as *released* is a framing this screen puts on a status,
     * not a new field of a payroll item — the Admin's period detail renders the same item and
     * has no use for the word. It is resolved here, on the server, so that no Vue computed and
     * no Blade template holds a second copy of the rule (decision 2-37).
     *
     * @return array<string, mixed>
     */
    private function row(Request $request, PayrollItem $item): array
    {
        return [
            ...(new PayrollItemResource($item))->toArray($request),
            'release' => $this->release($item),
        ];
    }

    /**
     * *Payslip*, or *Provisional* — and the sentence that says which, in words.
     *
     * Never a colour and never a badge on its own: DESIGN.md §5.6, and the difference between
     * "this is what you were paid" and "this is what you are likely to be paid" is the one
     * distinction on this screen that must survive greyscale, a screen reader and a printer.
     *
     * @return array{released: bool, label: string, note: string}
     */
    private function release(PayrollItem $item): array
    {
        $status = $item->period?->status;
        $month = $item->period?->label() ?? 'This month';

        if ($status === PayrollStatus::Paid) {
            return [
                'released' => true,
                'label' => 'Payslip',
                'note' => $month.' has been paid. These are the final figures.',
            ];
        }

        return [
            'released' => false,
            'label' => 'Provisional',
            'note' => sprintf(
                '%s has not been paid yet — it is %s. These figures can still change before payday.',
                $month,
                strtolower($status?->label() ?? 'not started'),
            ),
        ];
    }

    /**
     * Why the leave impact is what it is: *"3 unpaid days of 22 payable days in September 2026"*.
     *
     * **An unexplained deduction on a payslip is the single most likely thing in this
     * application to generate a complaint**, so the arithmetic is printed rather than implied.
     * The two counts are `PayrollService::unpaidDaysIn()` and `payableDaysIn()` — the same two
     * numbers `leaveImpactCents()` divided to produce the money — asked of the service rather
     * than recounted here, so the explanation cannot drift from the deduction it explains.
     *
     * **The money is the stored `leave_impact` and is never recomputed.** What the payslip says
     * was deducted is what was deducted; the days are the reason, not the source. That matters
     * on a released month above all: a schedule edited in November must not silently restate
     * September's figure on a document somebody has already been paid against.
     *
     * A month with no unpaid leave still gets a block, saying so. *"No unpaid leave this
     * month"* is an answer; a missing section leaves somebody wondering whether the screen
     * forgot, and it is the same sentence every month so it can be read at a glance.
     *
     * @return array{unpaid_days: int, payable_days: int, impact: string|null, has_impact: bool}
     */
    private function leaveExplanation(PayrollItem $item): array
    {
        $employee = $item->employee;
        $period = $item->period;

        if ($employee === null || $period === null) {
            return ['unpaid_days' => 0, 'payable_days' => 0, 'impact' => $item->leave_impact, 'has_impact' => false];
        }

        $unpaid = $this->payroll->unpaidDaysIn($employee, $period);

        return [
            'unpaid_days' => $unpaid,
            'payable_days' => $this->payroll->payableDaysIn($employee, $period),
            // The exact decimal string PostgreSQL holds. Never a float, never `toFixed`.
            'impact' => $item->leave_impact,
            'has_impact' => $unpaid > 0,
        ];
    }

    /**
     * `Payslip-Yaseen-Karim-2026-09.pdf`, or `Provisional-payslip-…` before the month is paid.
     *
     * The person and the month, as the brief asks, and the word that says what the file is:
     * a provisional figure saved to somebody's disk outlives the screen that framed it, so the
     * framing travels in the filename. `Str::slug` keeps it to ASCII letters, digits and
     * hyphens, which is the one spelling every operating system, mail client and browser
     * agrees about.
     */
    private function filename(PayrollItem $item, bool $released): string
    {
        $name = Str::slug($item->employee?->user?->name ?? 'employee');
        $month = $item->period?->month?->format('Y-m') ?? 'unknown';

        return sprintf('%s-%s-%s.pdf', $released ? 'Payslip' : 'Provisional-payslip', $name, $month);
    }

    /**
     * Who is reading — the name that goes at the top of their own payslip.
     *
     * The signed-in user's own name and employee id, never a lookup by a parameter: there is no
     * id in any of these three URLs that points at a person, which is what makes this screen
     * incapable of showing somebody else's name above somebody else's figures.
     *
     * @return array{id: int|null, name: string}
     */
    private function subject(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $user?->employee?->getKey() === null ? null : (int) $user->employee->getKey(),
            'name' => (string) ($user?->name ?? ''),
        ];
    }
}
