# Progress

## Current Goal
Improve the application in small safe steps.

## Agent Rules
- Do not ask questions unless truly blocked.
- Make reasonable assumptions and continue.
- Work on unfinished TODOs in order.
- Mark completed TODOs with [x].
- Add new bugs, ideas, or follow-up tasks as TODOs.
- Run tests/lint/build when available.
- Do not run destructive commands, force pushes, production deploys, or database resets.

## Active TODO
- [x] Review the project structure and identify the next safe improvement.
- [x] Fix the highest-priority failing test.
- [x] Improve the user-facing error state in the main flow.
- [x] Add a visible retry button when streamMessage fails (current session only).

## Completed
- [x] Added initial project notes.
- [x] Fix the highest-priority failing test (CalendarReminderRecordsTest).
- [x] Wire up streamError flag in MessageList with a visible "Try again" banner.
- [x] Add smoke test for the critical path (send → receive → clear, error banner).

## Backlog Ideas
- [ ] Improve developer setup documentation.

## Blocked
- None.
