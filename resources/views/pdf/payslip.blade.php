{{--
    The payslip as a PDF (master prompt Part D §14: "payslip = browser print view + PDF via
    dompdf — decision"; Phase 9, slice 2b).

    ## Why this file lives in `resources/views/pdf/` and not beside `app.blade.php`

    `resources/views/app.blade.php` is the Inertia ROOT view — one file, the shell every screen
    in this application mounts inside. This is not that. It is a document rendered by dompdf, a
    renderer with its own constraints that are the opposite of the app's: **no JavaScript, no
    Tailwind, no CSS variables, no `oklch()`, no flexbox and no grid.** A `pdf/` directory names
    that boundary, so nobody opens this file expecting a page and nobody adds a `@vite`
    directive to it — and it gives Phase 10's report exports somewhere to land that is not the
    root view's neighbour.

    ## Why it does not use the design tokens, and why that is not a violation

    DESIGN.md's rule is *never hard-code a colour in a Tailwind class*, and the reason is that
    `app.css` owns the light and dark values of every token. **A PDF has no theme.** It is ink
    on paper: there is no dark mode, no `prefers-color-scheme`, no stylesheet to inherit, and
    dompdf cannot read a CSS custom property or an `oklch()` value at all. So this document is
    deliberately **monochrome** — black text, grey rules, white paper — which also means there
    is no state on it carried by colour, and printing it on a mono laser loses nothing. The one
    thing that could have been a coloured badge, *provisional vs paid*, is a sentence instead.

    ## What is on it, and the one thing that is not

    Everything comes out of `PayrollItemResource` by way of `PayslipController::row()` — the
    same array the browser view renders, so the two cannot disagree about a figure.
    **`admin_notes` is not in that array** for anybody but an ADMIN and is not in it for the
    employee the note is about either (decision 9-10), so this template cannot print it: there
    is no key here to print. Nothing on this page ranks anybody against anybody (Part H §1).
--}}
@php
    /**
     * Money, as a string, for display only.
     *
     * The values arrive as the exact decimal strings PostgreSQL holds in `decimal(12,2)`.
     * Nothing here adds two of them together — the only sum on a payslip is `net_salary`, and
     * PostgreSQL computed that in a generated column. `NumberFormatter` is used when ext-intl
     * is present and the code falls back to `USD 2,200.00` when it is not, which is the same
     * two-branch behaviour `formatMoney()` has in TypeScript for an ISO code `Intl` does not
     * know. The VPS is not guaranteed to have ext-intl, so this must not require it.
     */
    $money = function (?string $amount) use ($currency): string {
        if ($amount === null) {
            return '—';
        }

        $value = (float) $amount;

        if (class_exists(\NumberFormatter::class)) {
            $formatted = (new \NumberFormatter('en_US', \NumberFormatter::CURRENCY))
                ->formatCurrency($value, $currency);

            if ($formatted !== false) {
                return $formatted;
            }
        }

        return $currency.' '.number_format($value, 2, '.', ',');
    };

    $lines = [
        ['label' => 'Base salary', 'amount' => $payslip['base_salary'], 'sign' => '+'],
        ['label' => 'Allowance', 'amount' => $payslip['allowance'], 'sign' => '+'],
        ['label' => 'Bonus', 'amount' => $payslip['bonus'], 'sign' => '+'],
        ['label' => 'Deduction', 'amount' => $payslip['deduction'], 'sign' => '−'],
        ['label' => 'Advance', 'amount' => $payslip['advance'], 'sign' => '−'],
        ['label' => 'Leave impact', 'amount' => $payslip['leave_impact'], 'sign' => '−'],
    ];

    // Polish 002: allowance is shown only when an older line still carries one.
    $lines = array_values(array_filter(
        $lines,
        fn (array $line): bool => $line['label'] !== 'Allowance' || (float) $line['amount'] !== 0.0,
    ));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $payslip['release']['label'] }} — {{ $payslip['employee']['name'] ?? 'Employee' }} — {{ $payslip['period']['label'] ?? '' }}</title>
    <style>
        @page { margin: 18mm 16mm; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 10.5pt;
            line-height: 1.45;
            color: #000000;
        }

        h1 { font-size: 16pt; margin: 0 0 2mm; }
        h2 { font-size: 11pt; margin: 8mm 0 2mm; text-transform: uppercase; letter-spacing: 0.5pt; }
        p  { margin: 0 0 2mm; }

        .muted { color: #444444; }
        .rule  { border-bottom: 0.6pt solid #000000; margin: 4mm 0; }

        .banner {
            border: 1pt solid #000000;
            padding: 3mm 4mm;
            margin: 0 0 6mm;
            font-weight: bold;
        }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 2mm 0; text-align: left; vertical-align: top; }
        th { font-weight: bold; }

        .figures th, .figures td { border-bottom: 0.4pt solid #999999; }
        .figures .amount { text-align: right; white-space: nowrap; }
        .figures .net th, .figures .net td {
            border-top: 1pt solid #000000;
            border-bottom: none;
            font-size: 13pt;
            font-weight: bold;
            padding-top: 3mm;
        }

        .meta td { padding: 1mm 0; }
        .meta .key { width: 35%; color: #444444; }

        .foot { margin-top: 10mm; font-size: 8.5pt; color: #444444; }
    </style>
</head>
<body>

{{--
    The banner is the first thing on the page and it is WORDS, never a tint. A provisional
    figure saved to somebody's disk outlives the screen that framed it, so the framing has to
    be on the document itself — and the filename says it too.
--}}
<div class="banner">
    @if ($payslip['release']['released'])
        Payslip — {{ $payslip['period']['label'] ?? '' }}
    @else
        PROVISIONAL — NOT A PAYSLIP
    @endif
</div>

<h1>{{ $payslip['employee']['name'] ?? 'Employee' }}</h1>
<p class="muted">{{ $payslip['release']['note'] }}</p>

<div class="rule"></div>

<h2>Month</h2>
<table class="meta">
    <tr>
        <td class="key">Pay period</td>
        <td>{{ $payslip['period']['label'] ?? 'Unknown' }}</td>
    </tr>
    <tr>
        <td class="key">Period status</td>
        <td>{{ $payslip['period']['status_label'] ?? 'Unknown' }}</td>
    </tr>
</table>

<h2>Figures</h2>
<table class="figures">
    <thead>
        <tr>
            <th scope="col">Item</th>
            <th scope="col" class="amount">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
            <tr>
                <th scope="row">{{ $line['label'] }}</th>
                <td class="amount">{{ $line['sign'] }}{{ $money($line['amount']) }}</td>
            </tr>
        @endforeach
        <tr class="net">
            <th scope="row">Net pay</th>
            <td class="amount">{{ $money($payslip['net_salary']) }}</td>
        </tr>
    </tbody>
</table>

{{--
    The leave impact, explained. An unexplained deduction on a payslip is the single most
    likely thing in this application to generate a complaint, so the arithmetic is written out:
    how many unpaid days, out of how many payable days in the month. The two counts are
    `PayrollService::unpaidDaysIn()` and `payableDaysIn()` — the same two numbers the deduction
    was divided from — and the money is the STORED figure, never recomputed here.

    A month with no unpaid leave still gets this section, saying so. A missing section leaves
    somebody wondering whether the document forgot.
--}}
<h2>Leave impact</h2>
@if ($leave['has_impact'])
    <p>
        {{ $money($leave['impact']) }} was deducted for
        {{ $leave['unpaid_days'] }} unpaid {{ \Illuminate\Support\Str::plural('day', $leave['unpaid_days']) }}
        out of {{ $leave['payable_days'] }} payable
        {{ \Illuminate\Support\Str::plural('day', $leave['payable_days']) }}
        in {{ $payslip['period']['label'] ?? 'this month' }}.
    </p>
    <p class="muted">
        Payable days are the working days in the month under your own schedule, holidays
        excluded. Paid leave is not deducted — only leave taken on an unpaid type is.
    </p>
@else
    <p>No unpaid leave this month, so nothing was deducted for it.</p>
@endif

<p class="foot">
    Generated on {{ $generatedOn }} by GoodTechies HQ.
    @unless ($payslip['release']['released'])
        These figures are provisional and can still change before payday.
    @endunless
</p>

</body>
</html>
