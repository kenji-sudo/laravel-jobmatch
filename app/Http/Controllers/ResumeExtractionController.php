<?php

namespace App\Http\Controllers;

use App\Models\Skill;
use App\Models\Student;
use App\Services\Matching\GeminiResumeExtractor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ResumeExtractionController extends Controller
{
    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'selected' => ['required', 'array', 'min:1', 'max:100'],
            'selected.*' => ['required', 'integer', 'distinct', 'exists:skills,id'],
            'levels' => ['required', 'array'],
        ]);
        $student = Student::where('user_id', $request->user()->id)->firstOrFail();
        $levels = [];
        foreach ($data['selected'] as $id) {
            $levels[$id] = $request->validate(['levels.'.$id => ['required', 'integer', 'between:1,5']])['levels'][$id];
        }
        DB::transaction(function () use ($student, $levels) {
            foreach ($levels as $id => $level) {
                $existing = $student->studentSkills()->where('skill_id', $id)->first();
                if ($existing) { $existing->update(['proficiency_level' => $level]); }
                else { $student->studentSkills()->create(['skill_id' => $id, 'proficiency_level' => $level, 'years_of_experience' => 0]); }
            }
        });
        return redirect()->route('portal.skills')->with('status', 'Confirmed skills saved. Other profile records were not changed.');
    }

    public function __invoke(Request $request, GeminiResumeExtractor $extractor): Response
    {
        $draft = null;
        $error = null;
        if ($request->isMethod('post')) {
            $request->validate(['resume' => ['required', 'file', 'mimes:pdf,docx', 'max:5120'], 'consent' => ['accepted']]);
            try { $draft = $extractor->extract($request->file('resume')); }
            catch (ConnectionException|RuntimeException $exception) { $error = 'Extraction is unavailable or the document could not be read. Your profile was not changed.'; }
        }
        return response()->view('jobmatch.resume-extraction', [
            'draft' => $draft, 'error' => $error,
            'catalog' => Skill::all()->keyBy(fn ($skill) => mb_strtolower(trim($skill->name))),
        ])->header('Cache-Control', 'no-store, private');
    }
}
