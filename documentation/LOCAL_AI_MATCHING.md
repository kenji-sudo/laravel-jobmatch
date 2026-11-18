# Local semantic job matching

Legacy prototype: the default is now Gemini + ML.NET. See `COLE_ALIGNED_AI.md`. This older provider is used only when `MATCHING_DRIVER=ollama` is explicitly selected.

## Implemented behavior

- `/portal/recommendations`: authenticated active students only, selected from the signed-in user's own profile. GET shows baseline skill coverage and never contacts the model.
- POST submits an optional career summary (maximum 2,000 characters) with CSRF protection and a six-requests-per-minute limit.
- The service starts with eligible jobs from active employers, excluding expired/closed postings. It selects up to 50 highest skill-coverage candidates and semantically reranks that shortlist.
- Input uses the summary, saved skill names and program. It does not automatically include account identity, email, grades, or PDF contents. Summary text and embeddings are not stored by this implementation, including rejected summary text in session old input.
- Job input puts title/skills before requirements/description. Each normalized input is limited to 1,600 characters. The page discloses shortening and candidate limits. Ollama token truncation is disabled; a model-context overflow produces the baseline fallback.
- Ollama returns embeddings; normalized cosine similarity (−1 to 1) drives semantic ordering. Existing required-skill coverage remains a separate percentage. Neither score is a hiring probability. There is no LLM-generated explanation or invented missing skill.
- No jobs, empty profiles, disabled models, timeouts, HTTP failures and invalid vectors are handled without invented AI output. Redirects are not followed. Provider failures expose no response body or resume text.

## Local server and Windows setup

Model: `all-minilm:22m-l6-v2-fp16`, listed in the official Ollama model catalog. First download requires internet; subsequent inference is local. This is a pretrained English-oriented embedding model, not a model trained on WCC placement outcomes.

1. Use a configured Laravel checkout containing this branch. Do not overwrite the working `www` folder with a source-only ZIP.
2. Double-click `ENABLE-LOCAL-AI.cmd`. If Ollama is missing, its official download page opens. Install it and run the launcher again.
3. The launcher checks/downloads the model and enables only the matching settings. Existing database settings and application key are preserved. A failed application health check restores the original `.env` file.
4. Restart an existing Laravel server, sign in as a student, open **Job Recommendations**, and choose **Find Semantic Matches**.

Manual maintainer configuration, if needed:

```dotenv
MATCHING_AI_ENABLED=true
MATCHING_OLLAMA_URL=http://127.0.0.1:11434
MATCHING_EMBEDDING_MODEL=all-minilm:22m-l6-v2-fp16
```

Only HTTP on literal loopback addresses (`127.0.0.1`, `[::1]`) is accepted. Hosted providers and redirects are rejected. A container deployment would need a separately reviewed endpoint policy; do not replace this with an arbitrary user-controlled URL. No API key is required.

`php artisan matching:check` probes actual model connectivity using two synthetic phrases and validates vectors. It returns a nonzero exit code on failure. It does not measure relevance accuracy. Disable the optional feature by setting `MATCHING_AI_ENABLED=false` and clearing cached Laravel configuration.

## Verification and limits

Feature tests exercise role/identity restrictions, model opt-in, rate limiting, summary validation, candidate/text limits, unchanged skill coverage, semantic ordering, endpoint restrictions, malformed vectors and outage fallback. The branch's CI additionally attempts a real Ollama model download and application health probe on a disposable runner. Check that job's actual result before reporting live model verification.

No automatic PDF text extraction, model training, learned hiring predictions, alumni recommendations or C# connection is included. The Windows launcher needs a real Windows/Laragon acceptance test; Linux CI does not prove the launcher works on the user's computer. Target-machine browser, database and responsiveness checks remain pending.

## Relevance evaluation before release

Use consented/anonymized student summaries and real eligible job descriptions, labelled independently by placement staff or reviewers. Start with a small held-out evaluation set, compare skill-only and semantic ordering, and report Precision@5 and relevance ratings with sample count and known limits. Use the header-only CSV in `research/matching-evaluation-template.csv` to organize labels; it is not a training dataset.

Check English/Filipino mixed text, unrelated careers, sparse skills, long text, changed jobs and unavailable models. Do not tune and evaluate on the same examples or equate a synthetic health check with accuracy. There is no validated accuracy percentage yet.

Official references:
- https://ollama.com/download/windows
- https://ollama.com/library/all-minilm/tags
- https://github.com/ollama/ollama/blob/main/docs/capabilities/embeddings.mdx
- https://sbert.net/examples/sentence_transformer/applications/semantic-search/README.html
