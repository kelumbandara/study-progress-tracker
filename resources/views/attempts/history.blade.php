@extends('adminlte::page')

@section('title', __('Question history'))
@section('content_header')
    <h1>{{ __('Question :n', ['n' => $question->question_no]) }} · {{ __($question->paper->subject->name) }} {{ $question->paper->label }}</h1>
@stop

@section('content')
    @php use App\Models\QuestionAttempt; @endphp
    @include('partials.flash')

    <div class="mb-3">
        <a class="btn btn-primary" href="{{ route('attempts.create', ['question' => $question->id]) }}"><i class="fas fa-redo"></i> {{ __('Try again') }}</a>
        <a class="btn btn-outline-secondary" href="{{ route('attempts.index') }}">{{ __('All attempts') }}</a>
    </div>

    <div class="card">
        <div class="card-body">
            <p class="mb-1"><strong>{{ __('Maximum marks:') }}</strong> {{ $question->max_marks }}
                · <strong>{{ __('Topics:') }}</strong> {{ $question->topics->pluck('name')->join(', ') ?: __('none linked yet') }}</p>
            @if ($improvement)
                <p class="mb-0">
                    <strong>{{ __('Improvement since first attempt:') }}</strong>
                    {{ __(':s percentage points', ['s' => ($improvement['score'] >= 0 ? '+' : '') . $improvement['score']]) }}
                    @if ($improvement['time'] !== null) · {{ $improvement['time'] <= 0 ? __(':n min faster', ['n' => abs($improvement['time'])]) : __(':n min slower', ['n' => $improvement['time']]) }}@endif
                    · {{ __('best score :n%', ['n' => $improvement['best']]) }}
                </p>
            @else
                <p class="mb-0 text-muted">{{ __('Only one attempt so far. Try again to measure improvement.') }}</p>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-body table-responsive p-0">
            <table class="table table-sm mb-0">
                <thead><tr><th>#</th><th>{{ __('Date') }}</th><th>{{ __('Marks') }}</th><th>{{ __('Score') }}</th><th>{{ __('Time') }}</th><th>{{ __('Result') }}</th><th>{{ __('Help') }}</th><th>{{ __('Mistake') }}</th><th>{{ __('Notes') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($attempts as $a)
                    <tr>
                        <td>{{ $a->attempt_no }}@if ($loop->first) <span class="badge badge-secondary">{{ __('first') }}</span>@endif</td>
                        <td>{{ $a->attempt_date->translatedFormat('d M Y') }}</td>
                        <td>{{ $a->marks_earned + 0 }} / {{ $a->max_marks + 0 }}</td>
                        <td>{{ $a->percent }}%</td>
                        <td>{{ $a->worked_minutes !== null ? $a->worked_minutes . ' min' : '—' }}</td>
                        <td>{{ __(QuestionAttempt::RESULTS[$a->result]) }}</td>
                        <td>{{ $a->independent ? __('Independent') : __('Assisted') }}{{ $a->checked_with_scheme ? ' · ' . __('scheme checked') : '' }}</td>
                        <td>{{ $a->mistake_category ? __(QuestionAttempt::MISTAKES[$a->mistake_category]) : '—' }}</td>
                        <td>{{ $a->notes ?: '—' }}</td>
                        <td><a href="{{ route('attempts.edit', $a) }}">{{ __('Correct this') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-center text-muted py-3">{{ __('No attempts yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@stop
