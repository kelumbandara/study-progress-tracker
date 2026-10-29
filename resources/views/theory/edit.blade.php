@extends('adminlte::page')

@section('title', __('Correct theory entry'))
@section('content_header')
    <h1>{{ __('Correct theory entry') }}</h1>
@stop

@section('content')
    @include('partials.flash')
    <a href="{{ route('theory.index') }}" class="btn btn-sm btn-outline-secondary mb-3">{{ __('← Back to theory entries') }}</a>

    <div class="alert alert-light border">
        <strong>{{ __($entry->topic->unit->subject->name) }} · {{ $entry->topic->full_name }}</strong>. {{ __('The topic and the "theory complete" tick cannot be changed here, so the completion history stays intact. Every correction is kept in the change history.') }}
    </div>

    <form method="POST" action="{{ route('theory.update', $entry) }}" novalidate>
        @csrf @method('PUT')
        <div class="card card-primary card-outline">
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group col-6 col-md-3"><label for="study_date">{{ __('Date') }}</label>
                        <input type="date" id="study_date" name="study_date" max="{{ $today }}" value="{{ old('study_date', $entry->study_date->toDateString()) }}" class="form-control" required></div>
                    <div class="form-group col-6 col-md-3"><label for="minutes">{{ __('Duration (minutes)') }}</label>
                        <input type="number" min="1" max="1440" id="minutes" name="minutes" value="{{ old('minutes', $entry->minutes) }}" class="form-control" required></div>
                    <div class="form-group col-6 col-md-3"><label for="confidence">{{ __('Confidence (1-5)') }}</label>
                        <select id="confidence" name="confidence" class="form-control"><option value="">—</option>
                            @for ($i = 1; $i <= 5; $i++)<option value="{{ $i }}" @selected((string) old('confidence', $entry->confidence) === (string) $i)>{{ $i }}</option>@endfor</select></div>
                </div>
                <div class="form-group mb-0"><label for="notes">{{ __('Notes') }}</label>
                    <textarea id="notes" name="notes" rows="2" maxlength="2000" class="form-control">{{ old('notes', $entry->notes) }}</textarea></div>
            </div>
            <div class="card-footer"><button class="btn btn-primary btn-lg btn-block"><i class="fas fa-save"></i> {{ __('Save correction') }}</button></div>
        </div>
    </form>

    @include('partials.audit-list', ['audit' => $audit])
@stop
