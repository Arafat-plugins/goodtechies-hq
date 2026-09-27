<?php

namespace App\Support;

/**
 * The three headings the Reports index groups its cards under (report contract §1).
 *
 * A group is a heading and nothing else: it decides no access and narrows no query. What a
 * viewer may open is `ReportKey::permission()`, asked per card, so a group that happens to be
 * empty for somebody is simply not drawn — it is never the reason a report is missing.
 */
enum ReportGroup: string
{
    /** The work itself: what there is, where it stands, what is late. */
    case Work = 'work';

    /** The people doing it: attendance and tracked hours. */
    case Workforce = 'workforce';

    /** What it earned and what it cost. */
    case Money = 'money';

    public function label(): string
    {
        return match ($this) {
            self::Work => 'Work',
            self::Workforce => 'Workforce',
            self::Money => 'Money',
        };
    }

    /**
     * The order the index draws them in — the order Part D §15 lists the reports.
     *
     * @return list<self>
     */
    public static function inDisplayOrder(): array
    {
        return [self::Work, self::Workforce, self::Money];
    }
}
