# Gemini + ML.NET integration

## Purpose and attribution

This implementation follows the five-feature vocabulary and Gemini/ML.NET direction in [ColeKenneth/jobmatch-ai, feature/ai, dcd9d27](https://github.com/ColeKenneth/jobmatch-ai/tree/dcd9d274c31559108c0bfcbad47e73f42dab9979). It is a companion integration for the existing Laravel portal, not an assertion that Cole's unfinished branch was already complete. Cole's code and branch are not overwritten.

The two existing applications use different database IDs and authentication systems. This implementation therefore keeps Laravel as the authenticated source of student/job records and sends only validated feature batches to a stateless C# inference service. It does not pretend that Laravel IDs are GUIDs or silently synchronize the two databases. Cole can later fold this explicit contract into his backend after review.

## Responsibilities

| Component | Responsibility |
| --- | --- |
| `GeminiResumeExtractor` | PDF input through Gemini document understanding; bounded DOCX XML text extraction; structured JSON and value validation |
| `ResumeExtractionController` | Active-student access, consent, review and explicit confirmation of approved skills |
| `MlNetJobMatcher` | Read actual confirmed records and calculate five features; preserve skill-based fallback |
| `MlNetClient` | Loopback-only authenticated transport, complete job-ID validation and explanation reconciliation |
| `ModelTrainer` | Validate labelled CSV, group holdout by profile, train FastTree and save artifact/metrics/hash |
| `ModelPredictor` | Load a configured artifact; return bounded scores and exact five-feature Shapley values |

No training, parsing or model inference code lives in the Blade page. No Gemini key or internal service token is sent to the browser.

## Resume workflow

Open **My Resume → Extract and Review Resume Skills**. PDF/DOCX is capped at 5 MB. The form explicitly explains that the document will be sent to Google Gemini and requires consent before transmission. The normal private PDF-upload feature remains separate and does not automatically send stored documents to Google.

The server uses a configured Gemini model ID, JSON schema and strict application validation. Unknown metadata and proficiency are null rather than invented. AI output is still a suggestion: students select approved catalog skills and manually confirm proficiency before saving. Unknown catalog skills require maintainer review. GWA, certificates and internships are not automatically overwritten by extraction. Documents and draft metadata are not persisted by the extraction workflow; draft responses use `no-store` headers. Google provider-side retention depends on the team's Google service arrangement and must be considered before using personal resumes.

## Features and explanations

1. Required skill ratio: matched required skill IDs / number of required skills; zero when no requirements are recorded.
2. GWA score: clamp `(3 - GWA) / 2` to 0–1, matching Cole's transform. A missing/invalid GWA produces a visible baseline fallback; no guessed academic score is inserted.
3. Proficiency depth: average confirmed skill proficiency, or zero when none is recorded.
4. Certification count: actual current certification records, not skill count.
5. Internship count: actual completed internship records, not all placement records.

Counts are capped at 100 and the inference contract validates ranges and finite values. Explanations evaluate all 32 five-feature coalitions against an all-zero reference; contributions plus the reference score reconcile to the reported clamped score. They describe model behavior, not causal hiring effects. This is not labelled TreeSHAP and the 0–100 score is not an employment probability.

## Credentials, runtime and training

Run `START-COLE-AI.cmd` inside a configured Windows project, or apply the supplied update ZIP to the existing project. The launcher requires PHP 8.5 and the official .NET 10 SDK. It prompts locally for the Gemini key using hidden input; do not send the key through chat or GitHub. Set a Gemini model ID actually enabled in the Google project; no potentially retired model is hardcoded. Configuration backups go into the ignored local backup folder.

The launcher generates a separate local integration secret. C# receives it through the child process environment; Laravel stores it in ignored `.env`. C# listens on `127.0.0.1:5291`. Requests require a matching bearer secret. Nothing configures public exposure, CORS or user-facing JWT bypasses.

No real labelled dataset or production artifact is supplied. The header-only template is `research/cole-features-training-template.csv`. Labels are reviewer-assessed relevance in 0–1, not fabricated hiring outcomes. Use anonymized profile groups and at least 50 valid rows from 10 profiles. The minimum is a technical guard, not a claim of adequate sample size for the thesis.

The launcher can select a CSV and train it. Maintainer equivalent:

```sh
dotnet run --project services/JobMatch.Inference -- train labelled.csv local-ai/model.zip
```

The model fits only the training split; holdout rows are grouped by profile. The adjacent JSON report records MAE, RMSE, row counts, feature schema, dataset hash and model hash. Review the held-out results and thesis methodology before releasing. Restart the inference service after replacing the artifact. Missing or incompatible artifacts yield 503 and an explicit Laravel fallback, never a dummy model.

## Verification and pending acceptance

PHP feature tests cover confirmed-data mapping, provider response validation, consent, failures, role access and atomic own-profile skill confirmation. C# tests train synthetic fixtures **only in temporary test folders**, then exercise the actual HTTP prediction API, artifact loading, authorization, input rejection and explanation additivity. Synthetic fixture scores do not establish recommendation accuracy. The Windows launchers receive syntax/regression checks; final configured Windows runtime acceptance remains separate.

Still required for a real deployment: the team's Gemini credentials/model, consented labelled data, a reviewed trained artifact, live Google extraction checks, browser/mobile/live-database testing and Cole's architecture review. Nothing in this branch merges into Cole's repository or deploys a site automatically.

Official references:
- https://ai.google.dev/gemini-api/docs/structured-output
- https://ai.google.dev/gemini-api/docs/document-processing
- https://learn.microsoft.com/en-us/dotnet/machine-learning/how-to-guides/train-machine-learning-model-ml-net
- https://learn.microsoft.com/en-us/dotnet/machine-learning/how-to-guides/save-load-machine-learning-models-ml-net
