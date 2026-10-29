@extends('adminlte::page')

@section('title', __('Correct attempt'))
@section('content_header')
    <h1>{{ __('Correct attempt :n', ['n' => $attempt->attempt_no]) }}</h1>
@stop

@section('content')
    @include('partials.flash')
    <a href="{{ route('attempts.history', $attempt->question_id) }}" class="btn btn-sm btn-outline-secondary mb-3">{{ __('← Back to question history') }}</a>

    <div class="alert alert-light border">
        <strong>{{ __($attempt->question->paper->subject->name) }} · {{ $attempt->question->paper->label }} · {{ __('Question :n', ['n' => $attempt->question->question_no]) }}</strong>
        {{ __('(maximum :m marks). The question and attempt number cannot be changed. Every correction is kept in the change history, and nothing is deleted.', ['m' => $attempt->max_marks + 0]) }}
    </div>

    <form method="POST" action="{{ route('attempts.update', $attempt) }}" novalidate>
        @csrf @method('PUT')
        <div class="card card-primary card-outline">
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group col-6 col-md-3"><label for="attempt_date">{{ __('Attempt date') }}</label>
                        <input type="date" id="attempt_date" name="attempt_date" max="{{ $today }}" value="{{ old('attempt_date', $attempt->attempt_date->toDateString()) }}" class="form-control" required></div>
                    <div class="form-group col-6 col-md-3"><label for="marks_earned">{{ __('Marks earned') }}</label>
                        <input type="number" step="0.01" min="0" id="marks_earned" name="marks_earned" value="{{ old('marks_earned', $attempt->marks_earned + 0) }}" class="form-control" required></div>
                    <div class="form-group col-6 col-md-3"><label for="worked_minutes">{{ __('Worked time (minutes)') }}</label>
                        <input type="number" min="0" max="1440" id="worked_minutes" name="worked_minutes" value="{{ old('worked_minutes', $attempt->worked_minutes) }}" class="form-control"></div>
                    <div class="form-group col-6 col-md-3"><label for="result">{{ __('Result') }}</label>
                        <select id="result" name="result" class="form-control" required>
                            @foreach ($results as $v => $l)<option value="{{ $v }}" @selected(old('result', $attempt->result) === $v)>{{ __($l) }}</option>@endforeach
                        </select></div>
                </div>
                <div class="form-row">
                    <div class="form-group col-12 col-md-4"><label>{{ __('Independent or assisted?') }}</label><div>
                        <div class="custom-control custom-radio custom-control-inline"><input type="radio" id="as_i" name="assistance" value="independent" class="custom-control-input" @checked(old('assistance', $attempt->independent ? 'independent' : 'assisted') === 'independent')><label class="custom-control-label" for="as_i">{{ __('Independent') }}</label></div>
                        <div class="custom-control custom-radio custom-control-inline"><input type="radio" id="as_a" name="assistance" value="assisted" class="custom-control-input" @checked(old('assistance', $attempt->independent ? 'independent' : 'assisted') === 'assisted')><label class="custom-control-label" for="as_a">{{ __('Assisted') }}</label></div></div></div>
                    <div class="form-group col-12 col-md-4"><label for="mistake_category">{{ __('Mistake category') }}</label>
                        <select id="mistake_category" name="mistake_category" class="form-control"><option value="">—</option>
                            @foreach ($mistakes as $v => $l)<option value="{{ $v }}" @selected(old('mistake_category', $attempt->mistake_category) === $v)>{{ __($l) }}</option>@endforeach</select></div>
                    <div class="form-group col-12 col-md-4 pt-md-4"><div class="custom-control custom-checkbox mt-md-2">
                        <input type="checkbox" class="custom-control-input" id="checked_with_scheme" name="checked_with_scheme" value="1" @checked(old('checked_with_scheme', $attempt->checked_with_scheme))>
                        <label class="custom-control-label" for="checked_with_scheme">{{ __('Checked against the marking scheme') }}</label></div></div>
                </div>
                <div class="form-group mb-0"><label for="notes">{{ __('Notes') }}</label>
                    <textarea id="notes" name="notes" rows="2" maxlength="2000" class="form-control">{{ old('notes', $attempt->notes) }}</textarea></div>
            </div>
            <div class="card-footer"><button class="btn btn-primary btn-lg btn-block"><i class="fas fa-save"></i> {{ __('Save correction') }}</button></div>
        </div>
    </form>

    @include('partials.audit-list', ['audit' => $audit])
@stop
