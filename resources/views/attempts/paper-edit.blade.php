@extends('adminlte::page')

@section('title', __('Correct paper attempt'))
@section('content_header')
    <h1>{{ __('Correct paper attempt :n', ['n' => $pa->attempt_no]) }}</h1>
@stop

@section('content')
    @include('partials.flash')
    <a href="{{ route('attempts.index') }}" class="btn btn-sm btn-outline-secondary mb-3">{{ __('← Back to attempt history') }}</a>

    <div class="alert alert-light border">
        <strong>{{ __($pa->paper->subject->name) }} · {{ $pa->paper->label }}</strong>{{ __('. The paper and attempt number cannot be changed. Every correction is kept in the change history.') }}
        @if ($totals['source'] === 'questions')
            <br><span class="text-warning-emphasis">{{ __('This attempt has question attempts linked to it, so its total is calculated from those (:m marks). The totals typed here are ignored while questions are linked; correct the individual question attempts instead.', ['m' => $totals['earned'] + 0]) }}</span>
        @endif
    </div>

    <form method="POST" action="{{ route('paper-attempts.update', $pa) }}" novalidate>
        @csrf @method('PUT')
        <div class="card card-primary card-outline">
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group col-6 col-md-3"><label for="attempt_date">{{ __('Attempt date') }}</label>
                        <input type="date" id="attempt_date" name="attempt_date" max="{{ $today }}" value="{{ old('attempt_date', $pa->attempt_date->toDateString()) }}" class="form-control" required></div>
                    <div class="form-group col-6 col-md-3"><label for="marks_earned">{{ __('Total marks earned') }}</label>
                        <input type="number" step="0.01" min="0" id="marks_earned" name="marks_earned" value="{{ old('marks_earned', $pa->marks_earned !== null ? $pa->marks_earned + 0 : '') }}" class="form-control"></div>
                    <div class="form-group col-6 col-md-3"><label for="max_marks">{{ __('Total maximum marks') }}</label>
                        <input type="number" step="0.01" min="0" id="max_marks" name="max_marks" value="{{ old('max_marks', $pa->max_marks !== null ? $pa->max_marks + 0 : '') }}" class="form-control"></div>
                    <div class="form-group col-6 col-md-3"><label for="worked_minutes">{{ __('Total worked time (min)') }}</label>
                        <input type="number" min="0" max="1440" id="worked_minutes" name="worked_minutes" value="{{ old('worked_minutes', $pa->worked_minutes) }}" class="form-control"></div>
                </div>
                <div class="form-row">
                    <div class="form-group col-12 col-md-4"><label>{{ __('Independent or assisted?') }}</label><div>
                        <div class="custom-control custom-radio custom-control-inline"><input type="radio" id="as_i" name="assistance" value="independent" class="custom-control-input" @checked(old('assistance', $pa->independent ? 'independent' : 'assisted') === 'independent')><label class="custom-control-label" for="as_i">{{ __('Independent') }}</label></div>
                        <div class="custom-control custom-radio custom-control-inline"><input type="radio" id="as_a" name="assistance" value="assisted" class="custom-control-input" @checked(old('assistance', $pa->independent ? 'independent' : 'assisted') === 'assisted')><label class="custom-control-label" for="as_a">{{ __('Assisted') }}</label></div></div></div>
                    <div class="form-group col-12 col-md-4 pt-md-4"><div class="custom-control custom-checkbox mt-md-2">
                        <input type="checkbox" class="custom-control-input" id="timed" name="timed" value="1" @checked(old('timed', $pa->timed))><label class="custom-control-label" for="timed">{{ __('Done under timed conditions') }}</label></div></div>
                </div>
                <div class="form-group mb-0"><label for="notes">{{ __('Notes') }}</label>
                    <textarea id="notes" name="notes" rows="2" maxlength="2000" class="form-control">{{ old('notes', $pa->notes) }}</textarea></div>
            </div>
            <div class="card-footer"><button class="btn btn-primary btn-lg btn-block"><i class="fas fa-save"></i> {{ __('Save correction') }}</button></div>
        </div>
    </form>

    @include('partials.audit-list', ['audit' => $audit])
@stop
