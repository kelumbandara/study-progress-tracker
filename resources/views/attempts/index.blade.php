@extends('adminlte::page')

@section('title', __('Attempt History'))
@section('content_header')
    <h1>{{ __('Attempt history') }}</h1>
@stop

@section('content')
    @php use App\Models\QuestionAttempt; @endphp
    @include('partials.flash')

    <div class="mb-3">
        <a class="btn btn-primary" href="{{ route('attempts.create') }}"><i class="fas fa-pen"></i> {{ __('Record question') }}</a>
        <a class="btn btn-outline-primary" href="{{ route('paper-attempts.create') }}"><i class="fas fa-file-signature"></i> {{ __('Record full paper') }}</a>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ __('Complete-paper attempts (latest 10)') }}</h3></div>
        <div class="card-body table-responsive p-0">
            <table class="table table-sm mb-0">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Paper') }}</th><th>#</th><th>{{ __('Marks') }}</th><th>{{ __('Time') }}</th><th>{{ __('Help') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($paperAttempts as $p)
                    @php $t = $p->totals(); @endphp
                    <tr>
                        <td>{{ $p->attempt_date->translatedFormat('d M Y') }}</td>
                        <td>{{ __($p->paper->subject->name) }} {{ $p->paper->label }}@if ($p->timed) <span class="badge badge-info">{{ __('timed') }}</span>@endif</td>
                        <td>{{ $p->attempt_no }}</td>
                        <td>
                            @if ($t['earned'] !== null){{ $t['earned'] + 0 }} / {{ ($t['max'] ?? 0) + 0 }}
                                @if ($t['max']) ({{ round($t['earned'] / $t['max'] * 100, 1) }}%)@endif
                                <small class="text-muted">· {{ $t['source'] === 'questions' ? __('from question marks') : __('from typed total') }}</small>
                            @else — @endif
                        </td>
                        <td>{{ $p->worked_minutes !== null ? $p->worked_minutes . ' min' : '—' }}@if ($p->paper->duration_minutes) <small class="text-muted">/ {{ $p->paper->duration_minutes }}</small>@endif</td>
                        <td>{{ $p->independent ? __('Independent') : __('Assisted') }}</td>
                        <td><a href="{{ route('paper-attempts.edit', $p) }}">{{ __('Correct this') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-3">{{ __('No complete-paper attempts yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">{{ __('Question attempts') }}</h3>
            <form class="card-tools form-inline" method="GET">
                <select name="subject" class="form-control form-control-sm mr-1" onchange="this.form.submit()" aria-label="{{ __('Filter by subject') }}">
                    <option value="">{{ __('All subjects') }}</option>
                    @foreach ($subjects as $s)<option value="{{ $s->id }}" @selected(request('subject') == $s->id)>{{ __($s->name) }}</option>@endforeach
                </select>
            </form>
        </div>
        <div class="card-body table-responsive p-0">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Question') }}</th><th>{{ __('Attempt') }}</th><th>{{ __('Marks') }}</th><th>{{ __('Time') }}</th><th>{{ __('Result') }}</th><th>{{ __('Help') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($attempts as $a)
                    <tr>
                        <td>{{ $a->attempt_date->translatedFormat('d M Y') }}</td>
                        <td><a href="{{ route('attempts.history', $a->question_id) }}">{{ __($a->question->paper->subject->name) }} {{ $a->question->paper->label }} · Q{{ $a->question->question_no }}</a></td>
                        <td>#{{ $a->attempt_no }}</td>
                        <td>{{ $a->marks_earned + 0 }} / {{ $a->max_marks + 0 }} ({{ $a->percent }}%)</td>
                        <td>{{ $a->worked_minutes !== null ? $a->worked_minutes . ' min' : '—' }}</td>
                        <td>{{ __(QuestionAttempt::RESULTS[$a->result]) }}</td>
                        <td>{{ $a->independent ? __('Independent') : __('Assisted') }}</td>
                        <td><a href="{{ route('attempts.edit', $a) }}">{{ __('Correct this') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">{{ __('No question attempts yet.') }} <a href="{{ route('attempts.create') }}">{{ __('Record your first one') }}</a>.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($attempts->hasPages())<div class="card-footer">{{ $attempts->links() }}</div>@endif
    </div>
@stop
