@extends('adminlte::page')

@section('title', __('Review Mistakes'))
@section('content_header')
    <h1>{{ __('Review mistakes') }}</h1>
@stop

@section('content')
    @include('partials.flash')
    <p class="text-muted">{!! __('Questions appear here when the latest attempt was wrong, scored low, needed help, was slow, or covers a topic you rated as difficult. A question only leaves this list after an <strong>independent</strong> attempt scoring :pct% or more; reading the solution is not enough.', ['pct' => config('tracker.mastery_pct')]) !!}</p>

    @forelse ($queue as $r)
        @php $q = $r['question']; $l = $r['latest']; @endphp
        <div class="card {{ $r['repeated'] ? 'card-outline card-danger' : 'card-outline card-warning' }}">
            <div class="card-header">
                <h3 class="card-title">{{ __($q->paper->subject->name) }} · {{ $q->paper->label }} · Q{{ $q->question_no }}</h3>
                <div class="card-tools">
                    @if ($r['repeated'])<span class="badge badge-danger">{{ __('Repeated difficulty (:n low attempts)', ['n' => $r['weak_attempts']]) }}</span>@endif
                </div>
            </div>
            <div class="card-body">
                <p class="mb-1"><strong>{{ __('Why:') }}</strong> {{ implode(' · ', $r['reasons']) }}</p>
                <p class="mb-1"><strong>{{ __('Latest:') }}</strong> {{ __('attempt #:no on :date: :earned/:max (:pct%), :how · :n attempt(s) in total', ['no' => $l->attempt_no, 'date' => $l->attempt_date->translatedFormat('d M Y'), 'earned' => $l->marks_earned + 0, 'max' => $l->max_marks + 0, 'pct' => $l->percent, 'how' => $l->independent ? __('independent') : __('assisted'), 'n' => $r['attempts']]) }}</p>
                <p class="mb-2 small text-muted">{{ __('Topics:') }} {{ $q->topics->pluck('name')->join(', ') ?: __('none linked') }}
                    @if ($r['last_review']) · {{ __('Last reviewed') }} {{ $r['last_review']->reviewed_on->translatedFormat('d M Y') }}@endif</p>

                <form method="POST" action="{{ route('mistakes.store') }}" class="border rounded p-2">
                    @csrf <input type="hidden" name="question_id" value="{{ $q->id }}">
                    <input type="hidden" name="reviewed_on" value="{{ $today }}">
                    <div class="d-flex flex-wrap">
                        @foreach (['theory_reviewed' => 'Theory reviewed', 'solution_understood' => 'Solution understood', 'reattempted' => 'Attempted again', 'mastered_independently' => 'Mastered independently'] as $f => $label)
                            <div class="custom-control custom-checkbox mr-3">
                                <input type="checkbox" class="custom-control-input" id="{{ $f }}{{ $q->id }}" name="{{ $f }}" value="1">
                                <label class="custom-control-label" for="{{ $f }}{{ $q->id }}">{{ __($label) }}</label>
                            </div>
                        @endforeach
                    </div>
                    <input type="text" name="notes" class="form-control form-control-sm my-2" placeholder="{{ __('Notes (optional)') }}" maxlength="2000">
                    <button class="btn btn-sm btn-primary">{{ __('Save review') }}</button>
                    <a class="btn btn-sm btn-outline-primary" href="{{ route('attempts.create', ['question' => $q->id]) }}"><i class="fas fa-redo"></i> {{ __('Try again') }}</a>
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('attempts.history', $q) }}">{{ __('History') }}</a>
                </form>
            </div>
        </div>
    @empty
        <div class="card"><div class="card-body text-center py-5">
            <p class="lead mb-1">{{ __('Nothing to review right now.') }}</p>
            <p class="text-muted mb-0">{{ __('Record question attempts and any that need work will show up here.') }}</p>
        </div></div>
    @endforelse
@stop
