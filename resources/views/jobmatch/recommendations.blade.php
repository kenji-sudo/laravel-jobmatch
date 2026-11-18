<x-jobmatch-layout portal title="Job Recommendations">
    <div class="workspace-heading">
        <span class="overline">YOUR CAREER OPTIONS</span>
        <h1>Job Recommendations</h1>
        <p class="muted">Explore open jobs using your confirmed career profile.</p>
    </div>
    <section class="card form-card">
        <h2>Find related opportunities</h2>
        @if(config('matching.driver') === 'ollama')
            <p>Compare profile and job text using the legacy local embedding prototype.</p>
        @else
            <p>Compare your confirmed skills, academic standing, proficiency, certifications and completed internships using the configured ML.NET model.</p>
            <a href="{{ route('portal.resume.extract') }}">Extract and review resume skills with Gemini</a>
        @endif
        <form method="POST" action="{{ route('portal.recommendations.run') }}" class="form-grid">
            @csrf
            @if(config('matching.driver') === 'ollama')
            <label class="full">
                Career summary (optional, up to 2,000 characters)
                <textarea name="summary" rows="5" maxlength="2000" aria-describedby="matching-help">{{ $summary }}</textarea>
            </label>
            <p id="matching-help" class="muted">Include relevant experience and interests. Your uploaded PDF is not automatically read. This summary is used for this request only; names, email and resume files are not added automatically.</p>
            @endif
            <div class="form-actions"><button class="button" type="submit">{{ config('matching.driver') === 'ollama' ? 'Find Semantic Matches' : 'Find AI Matches' }}</button></div>
        </form>
    </section>
    <section class="card" aria-labelledby="recommendation-results">
        <h2 id="recommendation-results">{{ match($result['mode']) { 'semantic' => 'Semantic matches', 'mlnet' => 'Model-ranked matches', default => 'Skill coverage results' } }}</h2>
        @if($result['reason'] === 'unavailable')
            <p role="status">{{ config('matching.driver') === 'ollama' ? 'Semantic matching is unavailable right now.' : 'The trained matching model is unavailable or not configured.' }} Showing skill coverage instead.</p>
        @elseif($result['reason'] === 'disabled')
            <p role="status">AI matching has not been enabled by the administrator. Showing skill coverage instead.</p>
        @elseif($result['reason'] === 'incomplete_profile')
            <p role="status">Complete your academic standing in My Profile before requesting model predictions. Showing skill coverage instead.</p>
        @elseif($result['reason'] === 'empty_profile')
            <p role="status">Add skills, complete your program or enter a career summary to use semantic matching.</p>
        @endif
        @if($result['mode'] === 'semantic')
            <p class="muted">Cosine similarity measures text relatedness from −1 to 1. Neither similarity nor skill coverage is a probability of getting hired.</p>
        @endif
        @if($result['mode'] === 'mlnet')
            <p class="muted">The model score is an estimate on a 0–100 scale, not a hiring probability. Feature contributions use an all-zero reference and describe this model, not real-world causes.</p>
        @endif
        @if($result['shortened'])
            <p class="muted">Long profile or job text was shortened for this comparison.</p>
        @endif
        @if($result['eligible_count'] > $result['candidate_count'])
            <p class="muted">Showing the top {{ $result['candidate_count'] }} skill-coverage candidates from {{ $result['eligible_count'] }} eligible jobs. AI matching reorders this shortlist.</p>
        @endif
        <div class="table-wrap">
            <table>
                <thead><tr><th>Job</th><th>Employer</th><th>Required-skill coverage</th>@if($result['mode'] === 'semantic')<th>Cosine similarity</th>@elseif($result['mode'] === 'mlnet')<th>Model score and explanation</th>@endif</tr></thead>
                <tbody>
                    @forelse($result['items'] as $row)
                        <tr>
                            <td><a href="{{ route('jobs.show', $row['job']) }}">{{ $row['job']->title }}</a></td>
                            <td>{{ $row['job']->employer?->company_name }}</td>
                            <td>{{ $row['score'] }}%</td>
                            @if($result['mode'] === 'semantic')<td>{{ number_format($row['semantic_similarity'], 4) }}</td>@endif
                            @if($result['mode'] === 'mlnet')
                                <td>{{ number_format($row['ml_score'], 2) }}/100
                                    <details><summary>View model contributions</summary>
                                        <p>Reference score: {{ number_format($row['base_score'], 2) }}</p>
                                        @php($featureLabels = ['RequiredSkillRatio'=>'Required skill coverage','GwaScore'=>'Academic standing','ProficiencyDepth'=>'Skill proficiency','CertificationCount'=>'Current certifications','InternshipCount'=>'Completed internships'])
                                        <ul>@foreach($row['contributions'] as $contribution)<li>{{ $featureLabels[$contribution['feature']] ?? $contribution['feature'] }}: {{ number_format($contribution['value'], 2) }} points</li>@endforeach</ul>
                                    </details>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $result['mode'] !== 'baseline' ? 4 : 3 }}">No open jobs are available right now.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-jobmatch-layout>
