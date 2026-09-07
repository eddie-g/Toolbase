---
name: qa
description: "Test, review, and validate application behavior in this repository. Use when asked for QA, regression testing, test plans, bug reproduction, code review, acceptance testing, or verification."
argument-hint: "Describe the feature, bug, or area to validate"
---

# QA

## Procedure

1. Translate the request into observable acceptance criteria and likely regression risks.
2. Locate the closest automated tests, relevant code paths, and available validation commands.
3. Run targeted checks first, expanding coverage only when risk or results justify it.
4. Reproduce failures with minimal, repeatable steps and preserve relevant output.
5. Inspect the changed behavior for boundary conditions, error paths, and regressions.
6. Report confirmed findings by severity, followed by test results, residual risks, and coverage gaps.

## Reporting

- Distinguish confirmed failures from risks, assumptions, and unverified concerns.
- For confirmed defects, include expected behavior, actual behavior, and reproducible steps.
- Lead code-review reports with findings ordered by severity.
- Do not modify production code unless explicitly asked to fix a finding.
- Do not claim a check passed unless it was executed successfully.