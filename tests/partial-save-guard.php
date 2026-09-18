<?php

/**
 * Static guard against the partial-save bug family.
 *
 * THE BUG THIS EXISTS TO CATCH
 * ---------------------------
 * Several core Joomla admin models are NOT safe to call with a partial payload.
 * They read keys off $data unconditionally, before any bind, so a key the caller
 * did not send evaluates to null and gets stored as an empty value.
 *
 * The worked example is com_menus. ItemModel::save() contains:
 *
 *     if ($table->parent_id == $data['parent_id'])   { ...
 *     if ($data['menuordering'] == -1)               { ...
 *     if ($data['menutype'] != $table->menutype)     { ...
 *
 * Omit `menutype` from an update and the item is stored with menutype = '' — it
 * belongs to no menu, renders nowhere, and vanishes from the Menus manager while
 * still holding its alias. Nothing errors. (cs-mcp-for-j#31.)
 *
 * WHAT THIS SCRIPT CHECKS
 * -----------------------
 * For every Update*Tool, that the tool either
 *   (a) declares it has been reviewed for this hazard, via a
 *       `@partial-save-safe <reason>` annotation in the class docblock, or
 *   (b) backfills the model's required keys from a previously-loaded row.
 *
 * It is a REMINDER, not a proof. It cannot know which keys a given core model
 * reads unconditionally. What it can do is stop a new Update*Tool being written
 * without anyone having thought about it — which is how #31 shipped.
 *
 * THE RUNTIME TEST IT CANNOT REPLACE
 * ----------------------------------
 * The only real proof is behavioural, against a live site:
 *
 *     1. create the entity with EVERY field populated
 *     2. read it back, keep the full row
 *     3. update exactly ONE field
 *     4. read it back again and diff
 *     5. anything that changed besides that field (and `modified`) is the bug
 *
 * Do that for each Update*Tool before release. It is the same procedure that
 * found #31, and it takes about a minute per tool.
 *
 * Run: php tests/partial-save-guard.php
 */

declare(strict_types=1);

$root = dirname(__DIR__) . '/packages/plg_system_csmcpforj/src/Tools';

if (!is_dir($root)) {
    fwrite(STDERR, "Tools directory not found: $root\n");
    exit(2);
}

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

foreach ($it as $f) {
    // Anchored: UpdateFooTool.php, not JoomlaUpdateHealthcheckTool.php.
    if ($f->isFile() && preg_match('/^Update\w+Tool\.php$/', $f->getFilename())) {
        $files[] = str_replace('\\', '/', $f->getPathname());
    }
}

sort($files);

echo "Partial-save guard — " . count($files) . " Update*Tool file(s)\n\n";

$unreviewed = [];

foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    $rel = substr($file, strpos($file, '/Tools/') + 7);

    $annotated = str_contains($src, '@partial-save-safe');

    // Heuristic: does it read an existing row and put any of it back into $data?
    $loadsExisting = (bool) preg_match('/\$existing\s*=\s*\$model->getItem\(/', $src);
    $backfills     = (bool) preg_match('/\$data\[[^\]]+\]\s*=\s*(\(int\)\s*)?\$existing->/', $src);

    if ($annotated) {
        printf("  %-46s reviewed (annotated)\n", $rel);
        continue;
    }
    if ($loadsExisting && $backfills) {
        printf("  %-46s backfills from the loaded row\n", $rel);
        continue;
    }

    printf("  %-46s NOT REVIEWED\n", $rel);
    $unreviewed[] = $rel;
}

echo "\n";

if (!$unreviewed) {
    echo "PASS — every Update*Tool is either annotated or backfills.\n";
    exit(0);
}

echo "REVIEW NEEDED — " . count($unreviewed) . " tool(s):\n\n";

foreach ($unreviewed as $rel) {
    echo "  - $rel\n";
}

echo <<<TXT

For each one, read the core model's save() and check whether it reads any \$data
key unconditionally. If it does, backfill that key from the loaded row. If it
does not, add to the class docblock:

    @partial-save-safe <the model>::save() binds only supplied keys; verified <date>

This script exits non-zero so it can gate a release, but "NOT REVIEWED" means
exactly that — unreviewed, not necessarily broken.

TXT;

exit(1);
