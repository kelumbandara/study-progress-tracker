@extends('adminlte::page')

@section('title', __('Record Question'))
@section('plugins.Select2', true)
@section('content_header')
    <h1>{{ __('Record a question attempt') }}</h1>
@stop

@section('content')
    @include('partials.flash')
    <form method="POST" action="{{ route('attempts.store') }}" novalidate>
        @csrf
        <div class="card card-primary card-outline">
            <div class="card-header"><h3 class="card-title">{{ __('1. Which question?') }}</h3></div>
            <div class="card-body">
                @include('partials.paper-picker', ['withQuestion' => true, 'pre' => $pre])
                <div class="form-group mb-0">
                    <label for="paper_attempt_id">{{ __('Part of a full-paper attempt?') }} <small class="text-muted">{{ __('(optional)') }}</small></label>
                    <select id="paper_attempt_id" name="paper_attempt_id" class="form-control"><option value="">{{ __('Not part of a full-paper attempt') }}</option></select>
                </div>
            </div>
        </div>

        <div class="card card-primary card-outline">
            <div class="card-header"><h3 class="card-title">{{ __('2. How did it go?') }}</h3></div>
            <div class="card-body">
                <p class="small text-muted">{{ __('A new attempt is always added; earlier attempts of the same question are never changed.') }}</p>
                <div class="form-row">
                    <div class="form-group col-6 col-md-3">
                        <label for="attempt_date">{{ __('Attempt date') }}</label>
                        <input type="date" id="attempt_date" name="attempt_date" value="{{ old('attempt_date', $today) }}" max="{{ $today }}" class="form-control" required>
                    </div>
                    <div class="form-group col-6 col-md-3">
                        <label for="marks_earned">{{ __('Marks earned') }}</label>
                        <input type="number" step="0.01" min="0" inputmode="decimal" id="marks_earned" name="marks_earned" value="{{ old('marks_earned') }}" class="form-control" required>
                    </div>
                    <div class="form-group col-6 col-md-3">
                        <label for="worked_minutes">{{ __('Worked time (minutes)') }}</label>
                        <input type="number" min="0" max="1440" inputmode="numeric" id="worked_minutes" name="worked_minutes" value="{{ old('worked_minutes') }}" class="form-control">
                        <small class="text-muted">{{ __('Duration only; part of your Write Exam Papers time.') }}</small>
                    </div>
                    <div class="form-group col-6 col-md-3">
                        <label for="result">{{ __('Result') }}</label>
                        <select id="result" name="result" class="form-control" required>
                            <option value="">{{ __('Choose…') }}</option>
                            @foreach ($results as $v => $l)<option value="{{ $v }}" @selected(old('result') === $v)>{{ __($l) }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-12 col-md-4">
                        <label>{{ __('Independent or assisted?') }}</label>
                        <div>
                            <div class="custom-control custom-radio custom-control-inline"><input type="radio" id="as_i" name="assistance" value="independent" class="custom-control-input" @checked(old('assistance', 'independent') === 'independent')><label class="custom-control-label" for="as_i">{{ __('Independent') }}</label></div>
                            <div class="custom-control custom-radio custom-control-inline"><input type="radio" id="as_a" name="assistance" value="assisted" class="custom-control-input" @checked(old('assistance') === 'assisted')><label class="custom-control-label" for="as_a">{{ __('Assisted') }}</label></div>
                        </div>
                    </div>
                    <div class="form-group col-12 col-md-4">
                        <label for="mistake_category">{{ __('Mistake category') }} <small class="text-muted">{{ __('(optional)') }}</small></label>
                        <select id="mistake_category" name="mistake_category" class="form-control">
                            <option value="">—</option>
                            @foreach ($mistakes as $v => $l)<option value="{{ $v }}" @selected(old('mistake_category') === $v)>{{ __($l) }}</option>@endforeach
                        </select>
                    </div>
                    <div class="form-group col-12 col-md-4 pt-md-4">
                        <div class="custom-control custom-checkbox mt-md-2">
                            <input type="checkbox" class="custom-control-input" id="checked_with_scheme" name="checked_with_scheme" value="1" @checked(old('checked_with_scheme'))>
                            <label class="custom-control-label" for="checked_with_scheme">{{ __('Checked against the marking scheme') }}</label>
                        </div>
                    </div>
                </div>
                <div class="form-group mb-0">
                    <label for="notes">{{ __('Notes') }} <small class="text-muted">{{ __('(optional)') }}</small></label>
                    <textarea id="notes" name="notes" rows="2" maxlength="2000" class="form-control">{{ old('notes') }}</textarea>
                </div>
            </div>
            <div class="card-footer"><button class="btn btn-primary btn-lg btn-block"><i class="fas fa-save"></i> {{ __('Save attempt') }}</button></div>
        </div>
    </form>
@stop

@section('js')
    <script src="{{ asset('js/paper-picker.js') }}"></script>
@stop
