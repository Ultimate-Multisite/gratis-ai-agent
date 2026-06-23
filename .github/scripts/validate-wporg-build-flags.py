#!/usr/bin/env python3
"""Validate WP.org/core build packaging stays in sync."""

from __future__ import annotations

import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
BUILD_SH = ROOT / "bin" / "build.sh"
MAIN_PLUGIN = ROOT / "superdav-ai-agent.php"
README = ROOT / "readme.txt"
DISTIGNORE = ROOT / ".distignore"


def error(message: str) -> None:
    print(f"ERROR: {message}", file=sys.stderr)


def distignore_entries() -> set[str]:
    entries: set[str] = set()
    for line in DISTIGNORE.read_text().splitlines():
        stripped = line.strip()
        if stripped and not stripped.startswith("#"):
            entries.add(stripped)
    return entries


def main() -> int:
    failures: list[str] = []
    script = BUILD_SH.read_text()
    main_plugin = MAIN_PLUGIN.read_text()
    readme = README.read_text()
    dist_entries = distignore_entries()

    if "Requires at least: 7.0" not in main_plugin:
        failures.append(
            "superdav-ai-agent.php must require WordPress 7.0+ after removing WP 6.9 compatibility shims."
        )

    if "Requires at least: 7.0" not in readme:
        failures.append(
            "readme.txt must require WordPress 7.0+ after removing WP 6.9 compatibility shims."
        )

    if "/advanced-plugin" not in dist_entries:
        failures.append(
            ".distignore must exclude /advanced-plugin so the WP.org/core zip never ships the advanced companion plugin."
        )

    if ".distignore" not in script or "--exclude-from=\"$exclude_file\"" not in script:
        failures.append(
            "bin/build.sh must build the core package through .distignore and rsync --exclude-from."
        )

    for removed_entry in ("includes/Compat", "lib/php-ai-client"):
        if (ROOT / removed_entry).exists():
            failures.append(
                f"{removed_entry} still exists; the core package now requires WordPress 7.0+ and must not bundle WP 6.9 shims."
            )

    if failures:
        for failure in failures:
            error(failure)
        return 1

    print("OK: WP.org/core build packaging, requirements, and split-plugin excludes are in sync.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
