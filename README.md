# WCC JobMatch — Laravel portal

**New: JobMatch Assistant.** Career guidance and app help through **AI Assistant** in the portal. See [setup and behavior](documentation/AI_ASSISTANT.md). Windows users can run `START-ASSISTANT.cmd` inside their configured project; chat reuses the Gemini credentials and needs no trained ML.NET model.

Laravel 12 application for World Citi College career records and employer opportunities. The frontend uses Blade, Livewire, Tailwind and the existing WCC branding. This repository runs independently of the C# / Blazor Solo application.

## Requirements

- PHP 8.5 with the extensions required by `composer.lock`; SQLite/PDO SQLite for automated tests.
- Composer 2 and Node.js 24 LTS with npm.
- SQLite for a new local review database, or the team's configured database connection.

Use the PHP version selected in Laragon for both the terminal and web server. PHP 8.3/8.4 cannot satisfy this project's PHP requirement. Install dependencies from the committed lock files.

## Fresh local checkout

Work in a new folder. Keep an existing `.env`, application key, database and uploaded resumes intact.

```sh
composer install
```

Copy `.env.example` to `.env` only if `.env` does not already exist. Set `APP_NAME="WCC JobMatch"` and `APP_URL=http://127.0.0.1:8000`. For a fresh SQLite database, retain `DB_CONNECTION=sqlite` and create the empty file `database/database.sqlite`. For another database, configure the connection using your own local credentials.

```sh
php artisan key:generate
php artisan migrate
npm ci --ignore-scripts
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Generate the application key only for a fresh installation. Open the address printed by Artisan. Development assets can instead use `npm run dev` in a separate terminal. `migrate:fresh` deletes existing data; do not use it on a shared database.

The default seeder creates no login accounts or sample data. Register student, alumni and employer accounts through the application. Staff accounts must be provisioned by an authorized maintainer; public signup cannot grant admin or placement officer roles.

### Optional local design samples

```sh
php artisan db:seed --class=JobMatchDesignSampleSeeder
```

This existing seeder is restricted to `APP_ENV=local`. It inserts sample companies, jobs and skills for design review, with random employer passwords. These are not real vacancies or AI training/evaluation data. Do not seed samples into a live database.

## Features and access

| Role | Available portal areas |
| --- | --- |
| Guest | Public jobs, skills, employers, about, registration and login |
| Student | Profile, own PDF resume (up to 5 MB), skills, certifications, own placements, job recommendations |
| Alumni | Dashboard and profile |
| Employer | Company, owned job postings, scoped placements |
| Admin / placement officer | Dashboard, profile, placement visibility and CSV report |
| Admin | Admin alumni area |

Record ownership and active-account checks apply beyond navigation visibility. Resume files use private local storage and an authenticated download route; do not expose their folder publicly.

## Job visibility and matching boundary

`JobPosting::openForDirectory()` admits active postings from active employer accounts with no deadline or a deadline on/after the start of the current application day. A date-only deadline remains open for that day. Public job detail pages and recommendation candidates share this rule.

`MatchService::score()` remains the existing required-skill overlap percentage. This is a baseline score, not a probability of employment or a validated AI model.

## Gemini extraction and ML.NET matching

The default driver is now `gemini_mlnet`, aligned with Cole's Gemini extraction / ML.NET feature-based approach. Students can optionally upload a PDF or DOCX to Gemini after explicit consent, review a draft and confirm selected approved skills. Predictions use confirmed database records, not unverified AI metadata.

The .NET 10 inference service in `services/JobMatch.Inference` uses actual certification and completed internship counts, requires a trained artifact, and returns a score plus exact five-feature Shapley contributions using a zero reference. It never auto-trains the three dummy examples in Cole's WIP branch. Scores are not calibrated hiring probabilities.

On a configured Windows checkout, double-click `START-COLE-AI.cmd`. It checks PHP 8.5 / .NET 10 SDK, prompts privately for a Gemini key and enabled model ID if missing, generates a local integration secret, and can train a supplied labelled CSV. No API key, real dataset or trained production artifact is shipped. Without them, the corresponding feature remains visibly unavailable and skill coverage still works.

See [Cole-aligned architecture and setup](documentation/COLE_ALIGNED_AI.md). The earlier Ollama code remains an explicitly selectable legacy prototype (`MATCHING_DRIVER=ollama`), not the default. Cole's original repository is not modified by this branch.

## Validation

```sh
php artisan test
npm run test:ui
npm run build
```

The GitHub Actions workflow runs these checks on PHP 8.5 and Node 24 without live credentials. See [validation and handoff](documentation/VALIDATION_AND_HANDOFF.md) for manual checks and remaining integration work, and [AI options](documentation/AI_OPTIONS.md) for an optional research proposal.

## Offline behavior and deployment

The service worker provides the generic offline fallback. It does not make authenticated workflows, database edits or resume access work offline. Test service workers on localhost or HTTPS.

Before deployment, configure the real database, HTTPS, mail delivery for password resets, `APP_DEBUG=false`, writable Laravel storage and a web root pointing to `public/`. Keep `.env`, application keys and private uploads out of Git. A successful PR merge alone does not deploy the application.

## Framework attribution

Built with [Laravel](https://laravel.com), an open-source framework licensed under the [MIT license](https://opensource.org/licenses/MIT). Existing framework and asset notices remain applicable.
