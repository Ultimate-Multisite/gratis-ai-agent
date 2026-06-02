#!/usr/bin/env python3
"""Validate WP.org build-time feature flag stripping stays in sync."""

from __future__ import annotations

import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
BUILD_SH = ROOT / "bin" / "build.sh"
MAIN_PLUGIN = ROOT / "superdav-ai-agent.php"
DISTIGNORE_WPORG = ROOT / ".distignore-wporg"


def error(message: str) -> None:
    print(f"ERROR: {message}", file=sys.stderr)


def extract_array(script: str, name: str) -> list[str]:
    match = re.search(
        rf"local\s+(?:-a\s+)?{re.escape(name)}=\(\s*(.*?)\s*\)",
        script,
        re.DOTALL,
    )
    if not match:
        raise ValueError(f"Could not find {name} array in {BUILD_SH}")

    return re.findall(r'"([^"]+)"', match.group(1))


def extract_comment_header(script: str, array_name: str) -> str:
    match = re.search(rf"(?m)^\s*local\s+(?:-a\s+)?{re.escape(array_name)}=\(", script)
    if not match:
        raise ValueError(f"Could not find {array_name} array in {BUILD_SH}")

    lines = script[: match.start()].splitlines()
    header: list[str] = []
    for line in reversed(lines):
        stripped = line.strip()
        if stripped.startswith("#"):
            header.append(stripped.lstrip("#").strip())
            continue
        if header or stripped:
            break
    return "\n".join(reversed(header))


def source_path(stripped_path: str) -> Path:
    normalized = stripped_path.replace("${dest}/", "", 1)
    return ROOT / normalized


def distignore_entries() -> set[str]:
    entries: set[str] = set()
    for line in DISTIGNORE_WPORG.read_text().splitlines():
        stripped = line.strip()
        if stripped and not stripped.startswith("#"):
            entries.add(stripped)
    return entries


def php_files_for_distignore_entry(entry: str) -> list[Path]:
    path = ROOT / entry
    if path.is_file() and path.suffix == ".php":
        return [path]
    if path.is_dir():
        return sorted(path.rglob("*.php"))
    return []


def main() -> int:
    failures: list[str] = []
    script = BUILD_SH.read_text()
    main_plugin = MAIN_PLUGIN.read_text()

    flag_entries = extract_array(script, "flags")
    flags = [entry.split(":", 1)[0] for entry in flag_entries]
    flag_set = set(flags)

    header = extract_comment_header(script, "flags").lower()
    for flag in flags:
        if flag.lower() not in header:
            failures.append(
                f"bin/build.sh flags header does not mention {flag}; "
                "keep the narrative comment in sync with the array."
            )

        define_pattern = re.compile(
            rf"(?:defined\(\s*'{re.escape(flag)}'\s*\)|define\(\s*'{re.escape(flag)}'\s*,)",
        )
        if not define_pattern.search(main_plugin):
            failures.append(
                f"bin/build.sh flags entry {flag} is not defined() or define()d "
                "in the unmodified superdav-ai-agent.php."
            )

    stripped = extract_array(script, "stripped_paths")
    dist_entries = distignore_entries()
    for item in stripped:
        source = source_path(item)
        source_rel = source.relative_to(ROOT).as_posix()
        if not source.exists():
            failures.append(
                f"bin/build.sh stripped_paths entry {item} maps to missing source path {source_rel}."
            )
        if source_rel not in dist_entries:
            failures.append(
                f"bin/build.sh stripped_paths entry {source_rel} is missing from .distignore-wporg."
            )

    if "Requires at least: 7.0" not in script:
        failures.append(
            "bin/build.sh must force the WP.org package header to Requires at least: 7.0 "
            "when stripping WP 6.9 compatibility shims."
        )

    for required_entry in ("includes/Compat", "lib/php-ai-client"):
        if required_entry not in dist_entries:
            failures.append(
                f".distignore-wporg is missing {required_entry}; WP.org builds must strip WP 6.9 compatibility shims."
            )
        if f"${{dest}}/{required_entry}" not in stripped:
            failures.append(
                f"bin/build.sh stripped_paths is missing ${{dest}}/{required_entry}; "
                "the build must fail if a compatibility shim leaks into the WP.org package."
            )

    feature_constant = re.compile(r"SD_AI_AGENT_FEATURE_[A-Z0-9_]+")
    for entry in sorted(dist_entries):
        if not entry.startswith("includes/Abilities/"):
            continue
        for php_file in php_files_for_distignore_entry(entry):
            constants = sorted(set(feature_constant.findall(php_file.read_text())))
            for constant in constants:
                if constant not in flag_set:
                    failures.append(
                        f".distignore-wporg ability source {entry} references {constant}, "
                        "but bin/build.sh flags does not force that constant false."
                    )

    if failures:
        for failure in failures:
            error(failure)
        return 1

    print("OK: WP.org build flags, defines, stripped paths, and .distignore-wporg are in sync.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
