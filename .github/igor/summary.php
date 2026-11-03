<?php

declare(strict_types=1);

/*
 * Turns igor.json into the job summary.
 *
 * The split that matters is project vs vendor. Run inside this checkout, Sylius
 * IS the project, so its own services land under `src/Sylius/...` and every
 * other package under `vendor/...`. Only the first group is something a pull
 * request here can do anything about; the second is a reading list for other
 * maintainers and is collapsed away.
 *
 * Counts, not verdicts. Nothing in this file fails anything.
 */

$path = $argv[1] ?? null;

if (null === $path || !is_file($path)) {
    fwrite(\STDERR, "usage: summary.php <igor.json>\n");

    exit(0);
}

$entries = json_decode((string) file_get_contents($path), true);

if (!\is_array($entries)) {
    fwrite(\STDERR, "could not read {$path}\n");

    exit(0);
}

// Patterns to exclude from the summary. These are still in igor.json and
// igor.txt, but do not appear in the GitHub job summary. Useful for known
// false positives or low-value findings that clutter the report.
$ignorePatterns = [
    'Mutation detected on a local reference to a shared service',
];

// Checks whether any ignore pattern matches the finding message.
$shouldIgnore = static function (string $message, array $patterns): bool {
    foreach ($patterns as $pattern) {
        if (str_contains($message, $pattern)) {
            return true;
        }
    }

    return false;
};

$buckets = [
    'project' => ['services' => 0, 'findings' => 0, 'errors' => 0, 'top' => []],
    'vendor' => ['services' => 0, 'findings' => 0, 'errors' => 0, 'top' => []],
];

foreach ($entries as $entry) {
    if (!\is_array($entry)) {
        continue;
    }

    $filePath = (string) ($entry['file_path'] ?? '');

    // Igor prints a relative path for some entries and an absolute one for
    // others (`vendor/foo/...` next to `/home/runner/work/.../vendor/foo/...`),
    // so a prefix test alone silently files every absolute third-party path
    // under "project" - 163 of them on the first run of this script.
    $isVendor = str_starts_with($filePath, 'vendor/') || str_contains($filePath, '/vendor/');
    $group = $isVendor ? 'vendor' : 'project';
    $findings = \is_array($entry['findings'] ?? null) ? $entry['findings'] : [];

    // Filter findings: keep only those that do not match any ignore pattern.
    $findings = array_filter($findings, static function (array $finding) use ($shouldIgnore, $ignorePatterns): bool {
        $message = (string) ($finding['message'] ?? '');

        return !$shouldIgnore($message, $ignorePatterns);
    });

    // Skip services that have no findings after filtering.
    if (0 === \count($findings)) {
        continue;
    }

    $errors = 0;
    foreach ($findings as $finding) {
        if ('ERROR' === ($finding['severity'] ?? null)) {
            ++$errors;
        }
    }

    ++$buckets[$group]['services'];
    $buckets[$group]['findings'] += \count($findings);
    $buckets[$group]['errors'] += $errors;

    $buckets[$group]['top'][] = [
        'id' => (string) ($entry['service_id'] ?? '?'),
        'file' => $filePath,
        'findings' => \count($findings),
        'errors' => $errors,
        'message' => (string) ($findings[0]['message'] ?? ''),
    ];
}

$sortByWeight = static function (array &$rows): void {
    usort($rows, static fn (array $a, array $b): int => [$b['errors'], $b['findings']] <=> [$a['errors'], $a['findings']]);
};

echo "### Igor - static worker-mode audit\n\n";
echo "Advisory. This step never fails the job: the analysis is a heuristic and ";
echo "cannot tell a mutation of an injected dependency from a plain method call ";
echo "on it, so most rows below are false positives. Read it as a shortlist, not a verdict.\n\n";

echo "| scope | services with findings | findings | of them ERROR |\n";
echo "|---|---:|---:|---:|\n";
printf(
    "| Sylius (`src/`) | %d | %d | %d |\n",
    $buckets['project']['services'],
    $buckets['project']['findings'],
    $buckets['project']['errors'],
);
printf(
    "| third-party (`vendor/`) | %d | %d | %d |\n\n",
    $buckets['vendor']['services'],
    $buckets['vendor']['findings'],
    $buckets['vendor']['errors'],
);

if (0 === $buckets['project']['services']) {
    echo "No findings in Sylius' own services. Everything reported sits in third-party packages.\n\n";
} else {
    $sortByWeight($buckets['project']['top']);

    echo "#### Sylius services, by weight\n\n";
    echo "A service disappears from this list once it is tagged `kernel.reset` - that ";
    echo "is how the already-fixed ones stopped showing up.\n\n";
    echo "| service | findings | first message |\n";
    echo "|---|---:|---|\n";

    foreach (\array_slice($buckets['project']['top'], 0, 40) as $row) {
        printf(
            "| `%s` | %d | %s |\n",
            $row['id'],
            $row['findings'],
            str_replace('|', '\\|', mb_substr($row['message'], 0, 120)),
        );
    }

    if (\count($buckets['project']['top']) > 40) {
        printf("\n...and %d more, in the artifact.\n", \count($buckets['project']['top']) - 40);
    }

    echo "\n";
}

$sortByWeight($buckets['vendor']['top']);

echo "<details><summary>Third-party packages (" . $buckets['vendor']['services'] . " services)</summary>\n\n";
echo "Not actionable here. Worth forwarding to the respective maintainers.\n\n";
echo "| service | findings |\n|---|---:|\n";

foreach (\array_slice($buckets['vendor']['top'], 0, 25) as $row) {
    printf("| `%s` | %d |\n", $row['id'], $row['findings']);
}

echo "\n</details>\n\n";
echo "Full output: the `igor-audit` artifact (`igor.txt`, `igor.json`).\n";
