<x-jobmatch-layout portal title="Job Recommendations">
    <div class="workspace-heading">
        <span class="overline">YOUR CAREER OPTIONS</span>
        <h1>Job Recommendations</h1>
        <p class="muted">Explore open jobs using your skills and an optional career summary.</p>
    </div>
    <section class="card form-card">
        <h2>Find related opportunities</h2>
        <p>Skill coverage is available below. Choose semantic matching to compare the meaning of your summary and skills with job descriptions on the local model server.</p>
        <form method="POST" action="{{ route('portal.recommendations.run') }}" class="form-grid">
            @csrf
            <label class="full">
                Career summary (optional, up to 2,000 characters)
                <textarea name="summary" rows="5" maxlength="2000" aria-describedby="matching-help">{{ $summary }}</textarea>
            </label>
            <p id="matching-help" class="muted">Include relevant experience and interests. Your uploaded PDF is not automatically read. This summary is used for this request only; names, email and resume files are not added automatically.</p>
            <div class="form-actions"><button class="button" type="submit">Find Semantic Matches</button></div>
        </form>
    </section>
    <section class="card" aria-labelledby="recommendation-results">
        <h2 id="recommendation-results">{{ $result['mode'] === 'semantic' ? 'Semantic matches' : 'Skill coverage results' }}</h2>
        @if($result['reason'] === 'unavailable')
            <p role="status">Semantic matching is unavailable right now. Showing skill coverage instead.</p>
        @elseif($result['reason'] === 'disabled')
            <p role="status">Semantic matching has not been enabled by the administrator. Showing skill coverage instead.</p>
        @elseif($result['reason'] === 'empty_profile')
            <p role="status">Add skills, complete your program or enter a career summary to use semantic matching.</p>
        @endif
        @if($result['mode'] === 'semantic')
            <p class="muted">Cosine similarity measures text relatedness from −1 to 1. Neither similarity nor skill coverage is a probability of getting hired.</p>
        @endif
        @if($result['shortened'])
            <p class="muted">Long profile or job text was shortened for this comparison.</p>
        @endif
        @if($result['eligible_count'] > $result['candidate_count'])
            <p class="muted">Showing the top {{ $result['candidate_count'] }} skill-coverage candidates from {{ $result['eligible_count'] }} eligible jobs. Semantic matching reorders this shortlist.</p>
        @endif
        <div class="table-wrap">
            <table>
                <thead><tr><th>Job</th><th>Employer</th><th>Required-skill coverage</th>@if($result['mode'] === 'semantic')<th>Cosine similarity</th>@endif</tr></thead>
                <tbody>
                    @forelse($result['items'] as $row)
                        <tr>
                            <td><a href="{{ route('jobs.show', $row['job']) }}">{{ $row['job']->title }}</a></td>
                            <td>{{ $row['job']->employer?->company_name }}</td>
                            <td>{{ $row['score'] }}%</td>
                            @if($result['mode'] === 'semantic')<td>{{ number_format($row['semantic_similarity'], 4) }}</td>@endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $result['mode'] === 'semantic' ? 4 : 3 }}">No open jobs are available right now.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-jobmatch-layout>
