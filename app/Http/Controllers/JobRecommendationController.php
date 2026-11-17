<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\Matching\SemanticJobMatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

final class JobRecommendationController extends Controller
{
    public function __invoke(Request $request, SemanticJobMatcher $matcher): View
    {
        $student = Student::where('user_id', $request->user()->id)->firstOrFail();
        $data = [];
        if ($request->isMethod('post')) {
            $validator = Validator::make($request->only('summary'), ['summary' => ['nullable', 'string', 'max:2000']]);
            if ($validator->fails()) {
                // Keep rejected career text out of session old-input storage, too.
                throw new HttpResponseException(redirect()->route('portal.recommendations')->withErrors($validator));
            }
            $data = $validator->validated();
        }
        $summary = $data['summary'] ?? '';

        return view('jobmatch.recommendations', [
            'result' => $matcher->recommend($student, $summary, $request->isMethod('post')),
            'summary' => $summary,
        ]);
    }
}
