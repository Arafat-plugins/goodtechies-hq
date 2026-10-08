<?php

namespace App\Support;

use App\Services\SettingsService;
use Throwable;

/**
 * Polish 033: the service boxes a client's projects are sorted into on Admin → Projects
 * (Development, SEO, Maintenance, Marketing, …).
 *
 * A box is a name and the project types it gathers. The Admin edits the list — renames a box,
 * adds one, moves a type — from the client page; it is the `project_service_boxes` setting.
 * A type in no box is not lost: its projects land in an automatic "Other" box.
 */
final class ProjectServiceBoxes
{
    /** The most boxes the Admin can make. */
    public const MAX_BOXES = 12;

    /** The box for project types the Admin put in no box. */
    public const OTHER_KEY = 'other';

    /** @var list<array{name: string, types: list<string>}> */
    public const DEFAULT = [
        ['name' => 'Development', 'types' => ['website_development', 'woocommerce', 'web_application']],
        ['name' => 'SEO', 'types' => ['seo']],
        ['name' => 'Maintenance', 'types' => ['website_maintenance']],
        ['name' => 'Marketing', 'types' => ['marketing']],
    ];

    /**
     * The boxes as stored (or the default), cleaned: known types only, each type in one box.
     *
     * @return list<array{name: string, types: list<string>}>
     */
    public static function configured(): array
    {
        try {
            $stored = app(SettingsService::class)->get('project_service_boxes');
        } catch (Throwable) {
            $stored = null;
        }

        return self::clean(is_array($stored) && $stored !== [] ? $stored : self::DEFAULT);
    }

    /**
     * @param  array<int|string, mixed>  $boxes
     * @return list<array{name: string, types: list<string>}>
     */
    public static function clean(array $boxes): array
    {
        $known = array_column(ProjectType::cases(), 'value');
        $taken = [];
        $clean = [];

        foreach (array_values($boxes) as $box) {
            if (! is_array($box)) {
                continue;
            }

            $name = trim((string) ($box['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $types = [];

            foreach ((array) ($box['types'] ?? []) as $type) {
                $type = (string) $type;

                if (in_array($type, $known, true) && ! in_array($type, $taken, true)) {
                    $types[] = $type;
                    $taken[] = $type;
                }
            }

            $clean[] = ['name' => mb_substr($name, 0, 40), 'types' => $types];

            if (count($clean) === self::MAX_BOXES) {
                break;
            }
        }

        return $clean;
    }

    /**
     * Which box each project type falls in: type value → box key (`b0`, `b1`, … or `other`).
     *
     * @param  list<array{name: string, types: list<string>}>  $boxes
     * @return array<string, string>
     */
    public static function typeToBox(array $boxes): array
    {
        $map = [];

        foreach (ProjectType::cases() as $type) {
            $map[$type->value] = self::OTHER_KEY;
        }

        foreach ($boxes as $index => $box) {
            foreach ($box['types'] as $type) {
                $map[$type] = 'b'.$index;
            }
        }

        return $map;
    }
}
