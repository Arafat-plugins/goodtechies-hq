<?php

namespace App\Services\Import;

use App\Services\Import\Draft\WorkspaceDraft;

/**
 * Turns one export file into a WorkspaceDraft. It reads; it never writes.
 *
 * The interface is this small on purpose. Everything a source is allowed to decide — which
 * column holds what, what its statuses mean here, what sits above a task in its hierarchy — is
 * inside `read()`. Everything a source is NOT allowed to decide — what a valid task is, who may
 * be assigned one, whether a time entry needs approval — is on the other side of the draft, in
 * WorkspaceImporter, where there is one copy of it.
 */
interface SourceReader
{
    /** The `--from` value this reader answers to. */
    public function key(): string;

    /** How the source is named in the report: "ClickUp", "Asana". */
    public function label(): string;

    /**
     * The columns this reader looks for, first alias first, for `--help` and the report header.
     *
     * This is not documentation for its own sake. Until the client's real export has been
     * through the command (GATE A), every one of these is an assumption, and the report has to
     * say so where somebody reading it will see it.
     *
     * @return array<string, list<string>> what it is => the column names tried, in order
     */
    public function columns(): array;

    /**
     * The statuses this reader knows how to translate: the source's spelling => what it becomes
     * here. Anything outside this map is a reported skip, never a guess.
     *
     * @return array<string, string>
     */
    public function statusMap(): array;

    /**
     * @throws ImportFileException
     */
    public function read(CsvFile $file): WorkspaceDraft;
}
