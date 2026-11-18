<x-jobmatch-layout portal title="Review Resume Skills">
    <div class="workspace-heading"><span class="overline">YOUR CAREER PROFILE</span><h1>Review Resume Skills</h1><p class="muted">Extract a draft, check it, then choose what to add to your profile.</p></div>
    <section class="card form-card">
        <h2>Extract a draft with Gemini</h2>
        <p>This optional action sends the selected document to Google's Gemini service. It may contain personal information. Review or redact the document before uploading. AI suggestions can be wrong; your saved profile is never overwritten automatically.</p>
        <form class="form-grid" method="POST" action="{{ route('portal.resume.extract.run') }}" enctype="multipart/form-data">
            @csrf
            <label class="full">PDF or DOCX (maximum 5 MB)<input type="file" name="resume" accept=".pdf,.docx" required></label>
            <label class="full"><input type="checkbox" name="consent" value="1" required> I agree to send this document to Gemini for this extraction.</label>
            <div class="form-actions"><button class="button" type="submit">Extract and Review</button><a href="{{ route('portal.resume') }}">Back to My Resume</a></div>
        </form>
        @if($error)<p role="alert">{{ $error }}</p>@endif
    </section>
    @if($draft !== null)
        <section class="card">
            <h2>Draft skills — confirm before saving</h2>
            <p class="muted">Academic and experience metadata are suggestions only. Verified records remain the source for matching.</p>
            <p>Program: {{ $draft['degreeProgram'] ?? 'Not stated' }} · GWA: {{ $draft['gwa'] ?? 'Not stated' }} · Graduation: {{ $draft['graduationYear'] ?? 'Not stated' }}</p>
            <form class="form-grid" method="POST" action="{{ route('portal.resume.confirm') }}">
            @csrf
            @forelse(collect($draft['skills'])->unique(fn($skill) => mb_strtolower(trim($skill['name']))) as $skill)
                @php($existing = $catalog->get(mb_strtolower(trim($skill['name']))))
                <div class="card">
                    <h3>{{ $skill['name'] }}</h3>
                    @if($existing)
                        <label><input type="checkbox" name="selected[]" value="{{ $existing->id }}"> Add/update this skill</label>
                        <label>Confirm your proficiency<select name="levels[{{ $existing->id }}]"><option value="">Choose a level for selected skills</option>@foreach([1=>'Beginner',2=>'Basic',3=>'Intermediate',4=>'Advanced',5=>'Expert'] as $level=>$label)<option value="{{ $level }}">{{ $level }} — {{ $label }}</option>@endforeach</select></label>
                    @else
                        <p>This skill is not in the approved catalog. Ask the project maintainer to review it; it has not been added automatically.</p>
                    @endif
                </div>
            @empty
                <p>No skills were extracted. You can add your skills manually.</p>
            @endforelse
            <div class="form-actions"><button class="button" type="submit">Confirm and Save Selected Skills</button></div>
            </form>
            <a href="{{ route('portal.skills') }}">Review saved skills</a>
        </section>
    @endif
</x-jobmatch-layout>
