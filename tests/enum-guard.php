<?php

/**
 * Guard: no MCP tool schema may advertise an enum containing an empty string.
 *
 * WHY THIS EXISTS
 * ---------------
 * Google's Gemini API validates tool schemas against a strict OpenAPI subset.
 * A single enum member equal to "" makes it reject the ENTIRE tool catalogue
 * at parse time:
 *
 *     GenerateContentRequest.tools[0].function_declarations[26]
 *       .parameters.properties[robots].enum[0]: cannot be empty
 *
 * HTTP 400, before any prompt executes. Not one broken tool — no usable server
 * at all for any Gemini-backed client. Anthropic's API tolerates empty enum
 * members, which is exactly why this shipped unnoticed for months and was only
 * found when a customer tried Cline + gemini-2.5-flash (cs-mcp-for-j#29).
 *
 * WHY A TEST AND NOT A RUNTIME FILTER
 * -----------------------------------
 * The obvious fix is to strip "" from every enum as the catalogue serialises.
 * Do not do that. Empty string is sometimes load-bearing: the EventBooking
 * add-on's char(1) tri-states use "" to mean "inherit the global setting",
 * which is a third state distinct from "0" and "1". Silently deleting it would
 * remove the only advertised way to reach that state — breaking working
 * clients to appease a schema validator.
 *
 * The correct fix in that case was an explicit "inherit" sentinel mapped back
 * to "" on write. That is a decision a human has to make per field, so this
 * guard fails loudly and makes someone choose, rather than papering over it.
 *
 * USAGE
 * -----
 *     php tests/enum-guard.php [path ...]
 *
 * With no arguments it scans this repo plus the sibling add-on repos if they
 * are checked out next to it. Exit code 0 = clean, 1 = offending enum found.
 */

declare(strict_types=1);

$paths = array_slice($argv, 1);

if (!$paths) {
    $here = dirname(__DIR__);
    $siblings = [
        $here,
        dirname($here) . '/cs-mcp-for-j-addons-free',
        dirname($here) . '/cs-mcp-for-j-addons-pro',
        dirname($here) . '/cs-mcp-for-j3',
    ];
    $paths = array_values(array_filter($siblings, 'is_dir'));
}

$failures = [];
$filesScanned = 0;
$enumsSeen = 0;

foreach ($paths as $root) {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iter as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());

        if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
            continue;
        }

        // Skip this file — its own documentation quotes the offending pattern.
        if ($path === str_replace('\\', '/', __FILE__)) {
            continue;
        }

        $src = file_get_contents($path);
        if ($src === false) {
            continue;
        }

        $filesScanned++;

        // Pass 1 — inline enums:  'enum' => ['', 'a', 'b']
        if (preg_match_all("/'enum'\s*=>\s*\[([^\]]*)\]/", $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $i => [$body, $offset]) {
                $enumsSeen++;
                if (preg_match("/(?:^|,)\s*(''|\"\")\s*(?:,|$)/", $body)) {
                    $failures[] = [
                        'file' => $path,
                        'line' => substr_count(substr($src, 0, $offset), "\n") + 1,
                        'what' => 'inline enum contains an empty string',
                        'code' => trim($m[0][$i][0]),
                    ];
                }
            }
        }

        // Pass 2 — enums referencing a class constant:  'enum' => self::FOO
        // Resolve FOO in the same file and check its members.
        if (preg_match_all("/'enum'\s*=>\s*self::([A-Z_][A-Z0-9_]*)/", $src, $refs)) {
            foreach (array_unique($refs[1]) as $constName) {
                $enumsSeen++;
                $pattern = '/const\s+' . preg_quote($constName, '/') . '\s*=\s*\[([^\]]*)\]/';
                if (!preg_match($pattern, $src, $cm, PREG_OFFSET_CAPTURE)) {
                    continue; // declared elsewhere; nothing we can resolve statically
                }
                if (preg_match("/(?:^|,)\s*(''|\"\")\s*(?:,|$)/", $cm[1][0])) {
                    $failures[] = [
                        'file' => $path,
                        'line' => substr_count(substr($src, 0, $cm[0][1]), "\n") + 1,
                        'what' => "const {$constName} (used as an enum) contains an empty string",
                        'code' => trim($cm[0][0]),
                    ];
                }
            }
        }
    }
}

echo "enum-guard: scanned {$filesScanned} PHP files, inspected {$enumsSeen} enum declarations\n";

if (!$failures) {
    echo "PASS - no enum advertises an empty-string member.\n";
    exit(0);
}

echo "\nFAIL - " . count($failures) . " offending enum(s):\n\n";

foreach ($failures as $f) {
    echo "  {$f['file']}:{$f['line']}\n";
    echo "    {$f['what']}\n";
    echo "    " . (strlen($f['code']) > 120 ? substr($f['code'], 0, 117) . '...' : $f['code']) . "\n\n";
}

echo "Gemini rejects the whole tool catalogue when this is present.\n";
echo "Do NOT just delete the empty member if it carries meaning - replace it\n";
echo "with an explicit sentinel (e.g. \"inherit\") mapped back on write, the way\n";
echo "the EventBooking tri-states do.\n";

exit(1);
