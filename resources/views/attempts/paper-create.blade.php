@extends('adminlte::page')

@section('title', __('Record Full Paper'))
@section('content_header')
    <h1>{{ __('Record a complete paper') }}</h1>
@stop

@section('content')
    @include('partials.flash')
    <form method="POST" action="{{ route('paper-attempts.store') }}" novalidate>
        @csrf
        <div class="card card-primary card-outline">
            <div class="card-header"><h3 class="card-title">{{ __('Paper') }}</h3></div>
            <div class="card-body">@include('partials.paper-picker', ['withQuestion' => false, 'pre' => null])</div>
        </div>
        <div class="card card-primary card-outline">
            <div class="card-header"><h3 class="card-title">{{ __('Result') }}</h3></div>
            <div class="card-body">
                <div class="alert alert-light border small">
                    {{ __("If you also record the paper's questions one by one and link them to this attempt, the total is calculated from those question marks and the totals typed here are not added on top. Use the totals below when you only have the overall mark.") }}
                </div>
                <div class="form-row">
                    <div class="form-group col-6 col-md-3"><label for="attempt_date">{{ __('Attempt date') }}</label>
                        <input type="date" id="attempt_date" name="attempt_date" value="{{ old('attempt_date', $today) }}" max="{{ $today }}" class="form-control" required></div>
                    <div class="form-group col-6 col-md-3"><label for="marks_earned">{{ __('Total marks earned') }}</label>
                        <input type="number" step="0.01" min="0" id="marks_earned" name="marks_earned" value="{{ old('marks_earned') }}" class="form-control"></div>
                    <div class="form-group col-6 col-md-3"><label for="max_marks">{{ __('Total maximum marks') }}</label>
                        <input type="number" step="0.01" min="0" id="max_marks" name="max_marks" value="{{ old('max_marks') }}" class="form-control">
                        <small class="text-muted">{{ __("Blank = the paper's registered maximum.") }}</small></div>
                    <div class="form-group col-6 col-md-3"><label for="worked_minutes">{{ __('Total worked time (min)') }}</label>
                        <input type="number" min="0" max="1440" id="worked_minutes" name="worked_minutes" value="{{ old('worked_minutes') }}" class="form-control"></div>
                </div>
                <div class="form-row">
                    <div class="form-group col-12 col-md-4"><label>{{ __('Independent or assisted?') }}</label><div>
                        <div class="custom-control custom-radio custom-control-inline"><input type="radio" id="as_i" name="assistance" value="independent" class="custom-control-input" checked><label class="custom-control-label" for="as_i">{{ __('Independent') }}</label></div>
                        <div class="custom-control custom-radio custom-control-inline"><input type="radio" id="as_a" name="assistance" value="assisted" class="custom-control-input"><label class="custom-control-label" for="as_a">{{ __('Assisted') }}</label></div></div></div>
                    <div class="form-group col-12 col-md-4 pt-md-4"><div class="custom-control custom-checkbox mt-md-2">
                        <input type="checkbox" class="custom-control-input" id="timed" name="timed" value="1"><label class="custom-control-label" for="timed">{{ __('Done under timed conditions') }}</label></div></div>
                </div>
                <div class="form-group mb-0"><label for="notes">{{ __('Notes') }} <small class="text-muted">{{ __('(optional)') }}</small></label>
                    <textarea id="notes" name="notes" rows="2" maxlength="2000" class="form-control">{{ old('notes') }}</textarea></div>
            </div>
            <div class="card-footer"><button class="btn btn-primary btn-lg btn-block"><i class="fas fa-save"></i> {{ __('Save paper attempt') }}</button></div>
        </div>
    </form>
@stop

@section('js')
    <script src="{{ asset('js/paper-picker.js') }}"></script>
@stop
