@extends('adminlte::page')

@section('title', __('Past Papers'))
@section('content_header')
    <h1>{{ __('Past papers') }}</h1>
@stop

@section('content')
    @include('partials.flash')

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">{{ __('Registered papers') }}</h3>
            <form class="card-tools form-inline" method="GET">
                <select name="subject" class="form-control form-control-sm mr-1" onchange="this.form.submit()" aria-label="{{ __('Subject') }}">
                    <option value="">{{ __('All subjects') }}</option>
                    @foreach ($subjects as $s)<option value="{{ $s->id }}" @selected(request('subject') == $s->id)>{{ __($s->name) }}</option>@endforeach
                </select>
                <select name="year" class="form-control form-control-sm" onchange="this.form.submit()" aria-label="{{ __('Year') }}">
                    <option value="">{{ __('All years') }}</option>
                    @foreach ($years as $y)<option value="{{ $y }}" @selected(request('year') == $y)>{{ $y }}</option>@endforeach
                </select>
            </form>
        </div>
        <div class="card-body table-responsive p-0">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>{{ __('Year') }}</th><th>{{ __('Subject') }}</th><th>{{ __('Paper') }}</th><th>{{ __('Max marks') }}</th><th>{{ __('Duration') }}</th><th>{{ __('Availability') }}</th><th>{{ __('Questions') }}</th><th>{{ __('Attempts') }}</th></tr></thead>
                <tbody>
                @forelse ($papers as $p)
                    <tr>
                        <td>{{ $p->exam_year }}</td><td>{{ __($p->subject->name) }}</td>
                        <td><a href="{{ route('papers.show', $p) }}">{{ $p->paper_name }}</a>@if ($p->is_sample) <span class="badge badge-info">{{ __('SAMPLE') }}</span>@endif</td>
                        <td>{{ $p->max_marks ?? '—' }}</td><td>{{ $p->duration_minutes ? $p->duration_minutes . ' min' : '—' }}</td>
                        <td>{{ __($availability[$p->availability]) }}</td><td>{{ $p->questions_count }}</td><td>{{ $p->attempts_count }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">{{ __('No papers registered yet. Add one below or import a CSV/Excel file. Nothing is pre-filled because paper marks and durations must come from the real papers.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($papers->hasPages())<div class="card-footer">{{ $papers->links() }}</div>@endif
    </div>

    <div class="row">
        <div class="col-12 col-lg-6">
            <div class="card"><div class="card-header"><h3 class="card-title">{{ __('Register a paper') }}</h3></div>
                <form method="POST" action="{{ route('papers.store') }}" class="card-body">
                    @csrf
                    <div class="form-row">
                        <div class="form-group col-12 col-md-6"><label for="subject_id">{{ __('Subject') }}</label>
                            <select id="subject_id" name="subject_id" class="form-control" required>
                                @foreach ($subjects as $s)<option value="{{ $s->id }}" @selected(old('subject_id') == $s->id)>{{ __($s->name) }}</option>@endforeach
                            </select></div>
                        <div class="form-group col-6 col-md-3"><label for="exam_year">{{ __('Year') }}</label>
                            <select id="exam_year" name="exam_year" class="form-control" required>@foreach ($years as $y)<option value="{{ $y }}" @selected(old('exam_year') == $y)>{{ $y }}</option>@endforeach</select></div>
                        <div class="form-group col-6 col-md-3"><label for="paper_name">{{ __('Paper') }}</label>
                            <input id="paper_name" name="paper_name" value="{{ old('paper_name') }}" class="form-control" placeholder="Paper I" maxlength="32" required></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-6 col-md-4"><label for="max_marks">{{ __('Max marks') }}</label><input type="number" min="1" id="max_marks" name="max_marks" value="{{ old('max_marks') }}" class="form-control"></div>
                        <div class="form-group col-6 col-md-4"><label for="duration_minutes">{{ __('Official duration (min)') }}</label><input type="number" min="1" id="duration_minutes" name="duration_minutes" value="{{ old('duration_minutes') }}" class="form-control"></div>
                        <div class="form-group col-12 col-md-4"><label for="exam_type">{{ __('Exam type') }}</label><input id="exam_type" name="exam_type" value="{{ old('exam_type') }}" class="form-control" maxlength="64"></div>
                    </div>
                    <div class="form-group"><label for="paper_url">{{ __('Paper PDF link') }} <small class="text-muted">{{ __('(only where legally available)') }}</small></label><input type="url" id="paper_url" name="paper_url" value="{{ old('paper_url') }}" class="form-control"></div>
                    <div class="form-group"><label for="marking_scheme_url">{{ __('Marking scheme link') }}</label><input type="url" id="marking_scheme_url" name="marking_scheme_url" value="{{ old('marking_scheme_url') }}" class="form-control"></div>
                    <button class="btn btn-primary"><i class="fas fa-plus"></i> {{ __('Register paper') }}</button>
                </form>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card"><div class="card-header"><h3 class="card-title">{{ __('Import papers or questions') }}</h3></div>
                <form method="POST" action="{{ route('papers.import') }}" enctype="multipart/form-data" class="card-body">
                    @csrf
                    <div class="form-group"><label for="type">{{ __('What are you importing?') }}</label>
                        <select id="type" name="type" class="form-control"><option value="papers">{{ __('Paper details') }}</option><option value="questions">{{ __('Questions') }}</option></select></div>
                    <div class="form-group"><input type="file" name="file" accept=".csv,.txt,.xlsx,.xls" class="form-control-file" required></div>
                    <small class="text-muted d-block mb-2">
                        <strong>{{ __('Papers:') }}</strong> subject, year, paper (required); exam_type, max_marks, duration_minutes, paper_url, marking_scheme_url, availability.<br>
                        <strong>{{ __('Questions:') }}</strong> subject, year, paper, question_no, max_marks (required); topics (names or references separated by |), reference, notes. Import papers and the syllabus first.
                    </small>
                    <button class="btn btn-outline-primary"><i class="fas fa-upload"></i> {{ __('Import') }}</button>
                    @foreach ($imports as $im)
                        <hr class="my-2"><small><strong>{{ $im->filename }}</strong> ({{ $im->type }}): {{ __(':ok added, :bad errors', ['ok' => $im->rows_ok, 'bad' => $im->rows_failed]) }}
                            @if ($im->errors)<ul class="mb-0">@foreach (array_slice($im->errors, 0, 8) as $e)<li>{{ $e }}</li>@endforeach</ul>@endif</small>
                    @endforeach
                </form>
            </div>
        </div>
    </div>
@stop
