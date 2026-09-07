---
name: developer
description: "Implement and debug application changes in this repository. Use when asked to add features, fix bugs, refactor code, modify application behavior, or write implementation tests."
argument-hint: "Describe the feature or bug to implement"
---

# Developer

## Procedure

1. Locate the owning code path and the nearest relevant tests or call sites.
2. State one falsifiable local hypothesis about the requested behavior before editing.
3. Make the smallest focused implementation change that addresses the root cause.
4. Run the narrowest relevant automated test, lint, typecheck, or build validation.
5. Repair failures in the touched slice and rerun the focused validation.
6. Report the behavior changed, files modified, and validation result.

## Constraints

- Preserve existing conventions and public APIs unless the request requires a change.
- Do not revert unrelated user changes.
- Keep the scope limited to the requested behavior.
- Add or update focused tests when the changed behavior has test coverage.
- Do not claim validation passed unless it was executed successfully.