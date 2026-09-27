<?php

namespace App\Services\Import;

use RuntimeException;

/**
 * Thrown at the very end of a dry run, from inside the import's transaction, to roll it back.
 *
 * This is the mechanism that makes "--dry-run prints the same report a real run would" true by
 * construction rather than by discipline. The dry run is the real run: the same services write
 * the same rows through the same policies and hit the same constraints, the report is filled by
 * the same lines of code, and then the transaction is thrown away.
 *
 * The alternative — a separate "what would happen" pass — is a second implementation of the
 * import's rules, and the first time the two disagree is the morning somebody trusts the dry run
 * and runs the real one against their only copy of the data.
 *
 * It is caught in WorkspaceImporter::run() and nowhere else. Escaping to the command would be a
 * bug, not an error worth reporting, which is why it carries nothing.
 */
final class DryRunComplete extends RuntimeException {}
