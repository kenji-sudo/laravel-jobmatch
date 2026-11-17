# Optional AI matching proposal for Cole

Research only, checked 17 November 2026. No model, new runtime dependency, provider account or API key is installed/configured by this patch.

## Options to evaluate

| Option | Possible use | Tradeoff |
| --- | --- | --- |
| Sentence Transformers `all-MiniLM-L6-v2` | Lightweight English resume/job text embeddings for a first local prototype | Requires a separate Python runtime/service; must evaluate job relevance on your own data |
| Sentence Transformers `paraphrase-multilingual-MiniLM-L12-v2` | Candidate for mixed-language text | The documentation describes training on 50+ languages; validate actual Filipino/English resumes rather than assuming quality |
| Ollama embeddings API | Local HTTP embedding service callable from Laravel | Requires a running model server and model download; benchmark latency/memory on the target laptop |

Official references:
- https://sbert.net/docs/sentence_transformer/pretrained_models.html
- https://sbert.net/examples/sentence_transformer/applications/semantic-search/README.html
- https://github.com/ollama/ollama/blob/main/docs/capabilities/embeddings.mdx

## Proposed first experiment

Keep Laravel as the application and evaluate one local embedding service independently. Use only eligible jobs, with explicit skill matching retained as the transparent baseline. Cache job embeddings and recompute on job edits. Do not send personal resumes to a hosted provider by default.

Agree a contract before implementation: authenticated student context, validated text-size limits, job IDs, ranked results, model/version, score type and timeout behavior. Never show a cosine similarity score as an employment probability. An unavailable model should produce a clear baseline/unavailable state, not invented AI results.

With consent, prepare anonymized resume/job pairs labelled by the team or placement staff, split evaluation records from tuning data, and compare ranked results using Precision@5 and reviewer relevance ratings. Check missing skills, empty resumes, expired jobs, mixed-language text and service outages. Document limits and avoid unsupported accuracy claims.

Recommendation: start with MiniLM for an English-only proof of concept if Python is acceptable; consider Ollama when the team prefers a local HTTP model server. Cole should choose after confirming the thesis methodology and target machine. This is not a completed AI integration or trained research model.
