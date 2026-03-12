<?php

/**
 * Auto-increment patch version via git tag.
 *
 * Reads the latest semver tag (v*.*.*) and creates a new tag with patch+1.
 * If no tags exist, starts from v1.0.0.
 */

$lastTag = trim((string) shell_exec('git describe --tags --abbrev=0 --match "v*" 2>&1'));

if (strpos($lastTag, 'fatal') !== false || $lastTag === '') {
    $newTag = 'v1.0.0';
    shell_exec("git tag {$newTag}");
    echo "Version created: {$newTag}" . PHP_EOL;
    exit(0);
}

if (!preg_match('/^v(\d+)\.(\d+)\.(\d+)$/', $lastTag, $matches)) {
    echo "Cannot parse tag: {$lastTag}" . PHP_EOL;
    exit(1);
}

$major = (int) $matches[1];
$minor = (int) $matches[2];
$patch = (int) $matches[3];

$newTag = sprintf('v%d.%d.%d', $major, $minor, $patch + 1);

shell_exec("git tag {$newTag}");
echo "Version bumped: {$lastTag} -> {$newTag}" . PHP_EOL;
