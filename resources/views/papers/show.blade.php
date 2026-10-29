@extends('adminlte::page')

@section('title', $paper->label)
@section('plugins.Select2', true)
@section('content_header')
    <h1>{{ __($paper->subject->name) }} · {{ $paper->label }}</h1>
@stop

@section('content')
    @include('partials.flash')
    <a href="{{ route('papers.index') }}" class="btn btn-sm btn-outline-secondary mb-3">{{ __('← All papers') }}</a>

    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ __('Paper details') }}</h3></div>
        <form method="POST" action="{{ route('papers.update', $paper) }}" class="card-body">
            @csrf @method('PUT')
            <div class="form-row">
                <div class="form-group col-6 col-md-3"><label for="max_marks">{{ __('Max marks') }}</label><input type="number" min="1" id="max_marks" name="max_marks" value="{{ old('max_marks', $paper->max_marks) }}" class="form-control"></div>
                <div class="form-group col-6 col-md-3"><label for="duration_minutes">{{ __('Duration (min)') }}</label><input type="number" min="1" id="duration_minutes" name="duration_minutes" value="{{ old('duration_minutes', $paper->duration_minutes) }}" class="form-control"></div>
                <div class="form-group col-6 col-md-3"><label for="exam_type">{{ __('Exam type') }}</label><input id="exam_type" name="exam_type" value="{{ old('exam_type', $paper->exam_type) }}" class="form-control"></div>
                <div class="form-group col-6 col-md-3"><label for="availability">{{ __('Availability') }}</label>
                    <select id="availability" name="availability" class="form-control">@foreach ($availability as $v => $l)<option value="{{ $v }}" @selected(old('availability', $paper->availability) === $v)>{{ __($l) }}</option>@endforeach</select></div>
            </div>
            <div class="form-row">
                <div class="form-group col-12 col-md-6"><label for="paper_url">{{ __('Paper link') }}</label><input type="url" id="paper_url" name="paper_url" value="{{ old('paper_url', $paper->paper_url) }}" class="form-control"></div>
                <div class="form-group col-12 col-md-6"><label for="marking_scheme_url">{{ __('Marking scheme link') }}</label><input type="url" id="marking_scheme_url" name="marking_scheme_url" value="{{ old('marking_scheme_url', $paper->marking_scheme_url) }}" class="form-control"></div>
            </div>
            <button class="btn btn-primary btn-sm">{{ __('Save details') }}</button>
            @if ($paper->paper_url)<a class="btn btn-outline-primary btn-sm" href="{{ $paper->paper_url }}" target="_blank" rel="noopener noreferrer">{{ __('Open paper') }}</a>@endif
            @if ($paper->marking_scheme_url)<a class="btn btn-outline-primary btn-sm" href="{{ $paper->marking_scheme_url }}" target="_blank" rel="noopener noreferrer">{{ __('Open marking scheme') }}</a>@endif
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ __('Questions (:n)', ['n' => $paper->questions->count()]) }}</h3></div>
        <div class="card-body table-responsive p-0">
            <table class="table table-sm mb-0">
                <thead><tr><th>{{ __('No.') }}</th><th>{{ __('Max marks') }}</th><th style="min-width:260px">{{ __('Topics') }}</th><th>{{ __('Ref') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($paper->questions as $q)
                    <tr>
                        <td><a href="{{ route('attempts.history', $q) }}">Q{{ $q->question_no }}</a></td>
                        <td><input form="qf{{ $q->id }}" type="number" step="0.01" min="0.01" name="max_marks" value="{{ $q->max_marks + 0 }}" class="form-control form-control-sm" style="width:90px" aria-label="{{ __('Max marks') }}"></td>
                        <td><select form="qf{{ $q->id }}" name="topic_ids[]" multiple class="form-control topic-select" style="width:100%" data-selected="{{ $q->topics->pluck('id')->join(',') }}" aria-label="{{ __('Topics') }}"></select></td>
                        <td><input form="qf{{ $q->id }}" name="reference" value="{{ $q->reference }}" class="form-control form-control-sm" style="width:90px" aria-label="{{ __('Ref') }}"></td>
                        <td class="text-nowrap"><button form="qf{{ $q->id }}" class="btn btn-sm btn-outline-primary">{{ __('Save') }}</button>
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('attempts.create', ['question' => $q->id]) }}">{{ __('Attempt') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-3">{{ __('No questions registered for this paper yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @foreach ($paper->questions as $q)
        <form method="POST" action="{{ route('questions.update', $q) }}" id="qf{{ $q->id }}" class="d-none">@csrf @method('PUT')</form>
    @endforeach

    <div class="card card-primary card-outline">
        <div class="card-header"><h3 class="card-title">{{ __('Add a question') }}</h3></div>
        <form method="POST" action="{{ route('questions.store', $paper) }}" class="card-body">
            @csrf
            <div class="form-row">
                <div class="form-group col-6 col-md-2"><label for="question_no">{{ __('Question no.') }}</label><input id="question_no" name="question_no" value="{{ old('question_no') }}" class="form-control" maxlength="16" required></div>
                <div class="form-group col-6 col-md-2"><label for="qmax">{{ __('Max marks') }}</label><input type="number" step="0.01" min="0.01" id="qmax" name="max_marks" value="{{ old('max_marks') }}" class="form-control" required></div>
                <div class="form-group col-12 col-md-8"><label for="new_topics">{{ __('Syllabus topics (one or more)') }}</label><select id="new_topics" name="topic_ids[]" multiple class="form-control topic-select" style="width:100%" data-selected=""></select></div>
            </div>
            <div class="form-row">
                <div class="form-group col-12 col-md-4"><label for="reference">{{ __('PDF page / reference') }}</label><input id="reference" name="reference" value="{{ old('reference') }}" class="form-control" maxlength="64"></div>
                <div class="form-group col-12 col-md-8"><label for="qnotes">{{ __('Notes') }}</label><input id="qnotes" name="notes" value="{{ old('notes') }}" class="form-control" maxlength="1000"></div>
            </div>
            <button class="btn btn-primary"><i class="fas fa-plus"></i> {{ __('Add question') }}</button>
            <small class="text-muted ml-2">{{ __('No sub-question numbers: one row per main question.') }}</small>
        </form>
    </div>
@stop

@section('js')
<script>
    const topics = @json($topics);
    document.querySelectorAll('.topic-select').forEach(function (el) {
        const selected = (el.dataset.selected || '').split(',').filter(Boolean);
        const groups = {};
        topics.forEach(t => (groups[t.group] = groups[t.group] || []).push(t));
        Object.keys(groups).forEach(g => {
            const og = document.createElement('optgroup'); og.label = g;
            groups[g].forEach(t => { const o = new Option(t.text, t.id, false, selected.includes(String(t.id))); og.appendChild(o); });
            el.appendChild(og);
        });
        $(el).select2({ width: '100%', placeholder: @json(__('Search and pick topics')) });
    });
</script>
@stop
