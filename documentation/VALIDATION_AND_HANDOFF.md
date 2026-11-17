# Laravel validation and handoff

## Scope

Based on `feature/frontend` after PR #6, commit `68aaee54153179d8873fb68803ee4e6836b2b881`.

- Recommendation eligibility now reuses the public-directory rule. Expired/closed jobs and inactive employers are excluded; deadlines today and missing deadlines retain the current directory behavior.
- Required-skill scoring and ranking are unchanged. No AI provider or recommendation screen is added.
- Default database seeding no longer attempts to insert the obsolete `name` column or a demo account with a factory password. It is intentionally empty and repeatable.
- Project setup, role boundaries, optional samples and release checks are documented.

## Automated coverage added

`JobMatchRecommendationEligibilityTest` checks seven eligibility combinations against the actual public job endpoint and recommendation service, plus unchanged 100/50 skill scoring and ordering. `JobMatchDefaultSeederTest` checks that repeated default seeding creates no demo login accounts. These tests use the existing isolated SQLite test database.

The GitHub Actions workflow builds assets before running the full PHP suite and runs the existing frontend tests. It does not access the team's live database. Inspect the actual workflow result before claiming the new PHP tests passed.

## Manual acceptance — record actual results

These checks require the running application, actual browsers and the chosen database. They are not marked passed by source review or a green build.

| Check | Expected result | Result |
| --- | --- | --- |
| Fresh install with PHP 8.5 | Migrations and built assets load; empty states render | Pending |
| Student profile / credentials | Own edits persist; another student's records cannot be changed | Pending |
| Resume upload/download | Valid PDF up to 5 MB works; other accounts cannot download it | Pending |
| Employer company / jobs | Only owner can edit; expired posting can close without changing its deadline | Pending |
| Active posting or reopening | A past deadline is rejected | Pending |
| Role access | Guest redirects; unauthorized roles and inactive users get denied | Pending |
| Staff CSV | Correct scoped data, safe spreadsheet values, authorized access | Pending |
| Mobile / keyboard | Forms usable at 360 px and desktop; visible focus and readable errors | Pending |
| Offline then reconnect | Generic fallback appears; normal pages return online without stale private content | Pending |
| Real database | Save/update/download flows pass on the team's database driver | Pending |
| Password reset | Configured mail provider delivers and reset link works | Pending |

## Remaining decisions

1. Cole owns the AI/provider contract and final recommendation interface. Agree inputs, result format, failure behavior and evaluation before integration.
2. Review and merge this follow-up into `feature/frontend`. That is separate from a release merge to `main` and deployment.
3. Complete the manual checks above with named testers. Record date, browser, database and observed failures; do not label them complete without execution.

No live database migration, environment file, private upload or application secret is included in this change.
