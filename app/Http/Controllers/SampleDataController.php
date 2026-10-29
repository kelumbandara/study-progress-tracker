<?php

namespace App\Http\Controllers;

use App\Services\SampleData;
use Illuminate\Http\Request;

class SampleDataController extends Controller
{
    public function store(SampleData $sample)
    {
        return redirect()->route('settings.edit')->with('status', $sample->seed()
            ? __('Sample data loaded. It is labelled SAMPLE everywhere and can be removed below.')
            : __('Sample data is already loaded.'));
    }

    /** Needs the explicit confirmation field, so a stray request cannot delete anything. */
    public function destroy(Request $request, SampleData $sample)
    {
        $request->validate(['confirm' => ['required', 'in:remove']]);
        $c = $sample->remove();

        return redirect()->route('settings.edit')->with('status',
            __('Sample data removed (:u units, :p papers, :q questions, :a attempts, :l daily logs). Your own records were not touched.', ['u' => $c['units'], 'p' => $c['papers'], 'q' => $c['questions'], 'a' => $c['attempts'], 'l' => $c['logs']]));
    }
}
