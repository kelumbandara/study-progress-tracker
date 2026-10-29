@extends('adminlte::page')

@section('title', __('Record Theory'))
@section('plugins.Select2', true)
@section('content_header')
    <h1>{{ __('Record theory study') }}</h1>
@stop

@section('content')
    @include('partials.flash')

    <div class="card card-primary card-outline">
        <div class="card-header"><h3 class="card-title">{{ __('What did you study?') }}</h3></div>
        <form method="POST" action="{{ route('theory.store') }}" novalidate>
            @csrf
            <div class="card-body">
                <p class="small text-muted">{{ __('This time is part of the Study Theory hours in your Daily Log; it is never added on top of them. One entry per topic: studying several topics today means adding one entry for each.') }}</p>
                <div class="form-row">
                    <div class="form-group col-12 col-md-3"><label for="study_date">{{ __('Date') }}</label>
                        <input type="date" id="study_date" name="study_date" value="{{ old('study_date', $today) }}" max="{{ $today }}" class="form-control" required></div>
                    <div class="form-group col-12 col-md-6"><label for="topic_id">{{ __('Topic') }}</label>
                        <select id="topic_id" name="topic_id" class="form-control" style="width:100%" required>
                            <option value="">{{ __('Search for a topic…') }}</option>
                            @foreach ($topicGroups as $group => $items)
                                <optgroup label="{{ $group }}">@foreach ($items as $t)<option value="{{ $t->id }}" @selected(old('topic_id') == $t->id)>{{ $t->full_name }}</option>@endforeach</optgroup>
                            @endforeach
                        </select>
                        @if ($topicGroups->isEmpty())<small class="text-warning-emphasis">{{ __('No topics yet.') }} <a href="{{ route('syllabus.index') }}">{{ __('Load the syllabus first') }}</a>.</small>@endif
                    </div>
                    <div class="form-group col-6 col-md-3"><label for="minutes">{{ __('Duration (minutes)') }}</label>
                        <input type="number" min="1" max="1440" inputmode="numeric" id="minutes" name="minutes" value="{{ old('minutes') }}" class="form-control" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group col-6 col-md-3"><label for="confidence">{{ __('Confidence (1-5)') }}</label>
                        <select id="confidence" name="confidence" class="form-control"><option value="">—</option>@for ($i = 1; $i <= 5; $i++)<option value="{{ $i }}" @selected(old('confidence') == $i)>{{ $i }}</option>@endfor</select></div>
                    <div class="form-group col-12 col-md-5 pt-md-4"><div class="custom-control custom-checkbox mt-md-2">
                        <input type="checkbox" class="custom-control-input" id="marked_complete" name="marked_complete" value="1" @checked(old('marked_complete'))>
                        <label class="custom-control-label" for="marked_complete">{{ __('Theory for this topic is now complete') }}</label></div>
                        <small class="text-muted">{{ __('This does not mark the topic as mastered.') }}</small></div>
                </div>
                <div class="form-group mb-0"><label for="notes">{{ __('Notes') }} <small class="text-muted">{{ __('(optional)') }}</small></label>
                    <textarea id="notes" name="notes" rows="2" maxlength="2000" class="form-control">{{ old('notes') }}</textarea></div>
            </div>
            <div class="card-footer"><button class="btn btn-primary btn-lg btn-block"><i class="fas fa-save"></i> {{ __('Save theory entry') }}</button></div>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ __('Recent theory entries') }}</h3></div>
        <div class="card-body table-responsive p-0">
            <table class="table table-sm mb-0">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Subject') }}</th><th>{{ __('Topic') }}</th><th>{{ __('Time') }}</th><th>{{ __('Confidence') }}</th><th>{{ __('Complete?') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($entries as $e)
                    <tr><td>{{ $e->study_date->translatedFormat('d M Y') }}</td><td>{{ __($e->topic->unit->subject->name) }}</td><td>{{ $e->topic->full_name }}</td>
                        <td>{{ $e->minutes }} min</td><td>{{ $e->confidence ?? '—' }}</td><td>{{ $e->marked_complete ? __('Yes') : '—' }}</td>
                        <td><a href="{{ route('theory.edit', $e) }}">{{ __('Correct this') }}</a></td></tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-3">{{ __('No theory entries yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($entries->hasPages())<div class="card-footer">{{ $entries->links() }}</div>@endif
    </div>
@stop

@section('js')
    <script>$('#topic_id').select2({ width: '100%' });</script>
@stop
