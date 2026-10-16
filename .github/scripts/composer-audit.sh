#!/usr/bin/env bash
# `composer audit`, tolerant of an unreachable advisory endpoint and nothing else.
#
# Packagist's advisory API 502s from time to time, which fails the audit with a
# TransportException even though the lock file is fine. Retry the blip, then
# fall back to --ignore-unreachable, which suppresses only an unreachable
# advisory endpoint - a real advisory still exits non-zero and still fails the
# job, so the gate keeps its teeth.
#
# Shared by the Test job in ci-cd.yml, which audits the installed packages, and
# by audit.yml, which passes --locked to audit composer.lock without installing.
# Any arguments are passed straight through to `composer audit`.
set -uo pipefail

for attempt in 1 2 3; do
  if output="$(composer audit "$@" 2>&1)"; then
    printf '%s\n' "$output"
    exit 0
  fi
  printf '%s\n' "$output"

  # Anything that is not a download failure is a genuine result (advisories
  # found, or a malformed lock file). Fail immediately rather than burning
  # retries on it.
  if ! printf '%s\n' "$output" | grep -q 'could not be downloaded'; then
    exit 1
  fi

  echo "::warning::Advisory endpoint unreachable (attempt ${attempt}/3)."
  if [ "$attempt" -lt 3 ]; then
    sleep $((attempt * 15))
  fi
done

echo "::warning::Packagist advisory API still unreachable; re-running with --ignore-unreachable."
composer audit --ignore-unreachable "$@"
