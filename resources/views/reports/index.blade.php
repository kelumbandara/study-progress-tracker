@extends('adminlte::page')

@section('title', __('Reports'))
@section('content_header')
    <h1 class="no-print">{{ __('Reports') }}</h1>
@stop

@section('css')
<style>
    @media print {
        .main-sidebar, .main-header, .main-footer, .no-print, .content-header { display: none !important; }
        .content-wrapper, .content { margin: 0 !important; padding: 0 !important; background: #fff !important; }
        .card { box-shadow: none !important; border: 1px solid #ccc !important; break-inside: avoid; }
        a { color: inherit !important; text-decoration: none !important; }
    }
</style>
@stop

@section('content')
    @php
        use App\Support\Duration;
        $h = fn (?int $m) => $m === null ? __('Not configured') : Duration::format($m);
        $pct = fn ($v) => $v === null ? '—' : $v . '%';
        $m = $report['monthly'] ?? null;
    @endphp

    <form method="GET" class="card no-print">
        <div class="card-body">
            <div class="form-row">
                <div class="form-group col-6 col-md-2"><label for="type">{{ __('Report') }}</label>
                    <select id="type" name="type" class="form-control"><option value="weekly" @selected($type === 'weekly')>{{ __('Weekly') }}</option><option value="monthly" @selected($type === 'monthly')>{{ __('Monthly') }}</option></select></div>
                <div class="form-group col-6 col-md-2"><label for="anchor">{{ __('Week / month containing') }}</label>
                    <input type="date" id="anchor" name="anchor" value="{{ $anchor }}" class="form-control"></div>
                <div class="form-group col-6 col-md-2"><label for="from">{{ __('Or custom from') }}</label><input type="date" id="from" name="from" value="{{ $custom ? $from->toDateString() : '' }}" class="form-control"></div>
                <div class="form-group col-6 col-md-2"><label for="to">{{ __('to') }}</label><input type="date" id="to" name="to" value="{{ $custom ? $to->toDateString() : '' }}" class="form-control"></div>
                <div class="form-group col-12 col-md-2"><label for="subject">{{ __('Subject') }}</label>
                    <select id="subject" name="subject" class="form-control"><option value="">{{ __('All subjects') }}</option>
                        @foreach ($subjects as $s)<option value="{{ $s->id }}" @selected($subjectId === $s->id)>{{ __($s->name) }}</option>@endforeach</select></div>
                <div class="form-group col-12 col-md-2 d-flex align-items-end"><button class="btn btn-primary btn-block">{{ __('Show report') }}</button></div>
            </div>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('reports.export', $query + ['format' => 'xlsx']) }}"><i class="fas fa-file-excel"></i> Excel</a>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('reports.export', $query + ['format' => 'csv']) }}"><i class="fas fa-file-csv"></i> CSV</a>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fas fa-print"></i> {{ __('Print') }}</button>
        </div>
    </form>

    <h3>{{ __(':type report: :from to :to', ['type' => __($type), 'from' => $from->translatedFormat('d M Y'), 'to' => $to->translatedFormat('d M Y')]) }} <small class="text-muted">· {{ __($report['subject']) }}</small></h3>

    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ __('Hours: planned vs actual') }}</h3></div>
        <div class="card-body table-responsive p-0">
            <table class="table table-sm mb-0">
                <thead><tr><th>{{ __('Category') }}</th><th>{{ __('Planned for this range') }}</th><th>{{ __('Actual') }}</th><th>{{ __('Difference') }}</th><th>{{ __('Cumulative actual') }}</th></tr></thead>
                <tbody>
                @foreach ($report['hours']['rows'] as $c)
                    <tr><td>{{ __($c['label']) }}</td><td>{{ $h($c['planned_range']) }}</td><td>{{ $h($c['actual_range']) }}</td>
                        <td>{{ $c['planned_range'] === null ? '—' : ($c['actual_range'] >= $c['planned_range'] ? '+' : '−') . Duration::format(abs($c['actual_range'] - $c['planned_range'])) }}</td>
                        <td>{{ $h($c['cumulative']) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">
            {{ __(':logged of :days day(s) logged; :missing missing (not counted as zero). "Planned for this range" is the total plan spread evenly over the study period.', ['logged' => $report['consistency']['days_logged'], 'days' => $report['consistency']['days_in_range'], 'missing' => $report['consistency']['days_missing']]) }}
        </div>
    </div>

    <div class="row">
        <div class="col-12 col-lg-6">
            <div class="card h-100"><div class="card-header"><h3 class="card-title">{{ __('Questions') }}</h3></div>
                <div class="card-body">
                    @php $q = $report['questions']; @endphp
                    @if ($q['attempts'] === 0)<p class="text-muted mb-0">{{ __('No question attempts in this range.') }}</p>
                    @else
                        <p class="mb-1">{{ __(':a attempts on :d question(s); marks :e / :v (:pct).', ['a' => $q['attempts'], 'd' => $q['distinct'], 'e' => $q['earned'] + 0, 'v' => $q['available'] + 0, 'pct' => $pct($q['weighted_pct'])]) }}</p>
                        <p class="mb-0 small">{{ __('First attempts: :a (n=:an) · Retries: :b (n=:bn)', ['a' => $pct($q['first']['pct']), 'an' => $q['first']['n'], 'b' => $pct($q['retry']['pct']), 'bn' => $q['retry']['n']]) }}</p>
                    @endif
                    <h6 class="mt-3">{{ __('Timed papers') }}</h6>
                    @forelse ($report['timed_papers'] as $p)<div class="small">{{ $p['date'] }} · {{ $p['label'] }} ({{ __('attempt') }} {{ $p['attempt_no'] }}): {{ $pct($p['pct']) }}</div>
                    @empty<div class="small text-muted">{{ __('None in this range.') }}</div>@endforelse
                </div></div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card h-100"><div class="card-header"><h3 class="card-title">{{ __('Syllabus and topics') }}</h3></div>
                <div class="card-body">
                    <h6>{{ __('Theory completed in this range (:n)', ['n' => count($report['topics_completed'])]) }}</h6>
                    @forelse ($report['topics_completed'] as $t)<div class="small">{{ $t }}</div>@empty<div class="small text-muted">{{ __('None.') }}</div>@endforelse
                    <h6 class="mt-3">{{ __('Strongest topics') }}</h6>
                    @forelse ($report['strong'] as $s)<div class="small">{{ $s['name'] }}: {{ $s['pct'] }}% (n={{ $s['n'] }})</div>@empty<div class="small text-muted">Not enough data yet.</div>@endforelse
                    <h6 class="mt-3">{{ __('Weakest topics') }}</h6>
                    @forelse ($report['weak'] as $s)<div class="small">{{ $s['name'] }}: {{ $s['pct'] }}% (n={{ $s['n'] }})</div>@empty<div class="small text-muted">Not enough data yet.</div>@endforelse
                </div></div>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header"><h3 class="card-title">{{ __('Mistakes and revision') }}</h3></div>
        <div class="card-body">
            @php $v = $report['reviews']; @endphp
            {!! __('Reviewed :r (theory reviewed :t, solution understood :u, attempted again :a, mastered independently :m). <strong>Outstanding revision items: :o</strong>.', ['r' => $v['reviewed'], 't' => $v['theory'], 'u' => $v['understood'], 'a' => $v['reattempted'], 'm' => $v['mastered'], 'o' => $report['outstanding']]) !!}
        </div>
    </div>

    @if ($m)
        <div class="card">
            <div class="card-header"><h3 class="card-title">{{ __('Monthly detail') }}</h3></div>
            <div class="card-body">
                <h6>{{ __('Cumulative syllabus coverage') }}</h6>
                @foreach ($m['coverage'] as $cv)<div class="small">{{ __(':s: :t theory, :m mastered (:n items)', ['s' => __($cv['subject']), 't' => $pct($cv['theory_pct']), 'm' => $pct($cv['mastered_pct']), 'n' => $cv['total']]) }}</div>@endforeach
                <h6 class="mt-3">{{ __('Score trend by week') }}</h6>
                @foreach ($m['trend'] as $tr)
                    <div class="small"><strong>{{ __($tr['subject']) }}:</strong>
                        @forelse ($tr['weeks'] as $week => $w) {{ __('week of :w: :p (n=:n)', ['w' => $week, 'p' => $pct($w['pct']), 'n' => $w['n']]) }}{{ ! $loop->last ? ' ·' : '' }} @empty {{ __('no attempts') }} @endforelse</div>
                @endforeach
                <h6 class="mt-3">{{ __('Paper results to date (first attempt is the result; later attempts are retries)') }}</h6>
                @if (count($m['papers']['by_paper']))
                    <div class="table-responsive"><table class="table table-sm mb-1">
                        <thead><tr><th>{{ __('Year') }}</th><th>{{ __('Paper') }}</th><th>{{ __('First') }}</th><th>{{ __('Latest') }}</th><th>{{ __('Best') }}</th><th>{{ __('Attempts') }}</th><th>{{ __('Time (first) / official') }}</th></tr></thead>
                        <tbody>
                        @foreach (collect($m['papers']['by_paper'])->sortByDesc('year') as $p)
                            <tr><td>{{ $p['year'] }}</td><td>{{ $p['label'] }}</td><td>{{ $pct($p['first_pct']) }}</td><td>{{ $pct($p['latest_pct']) }}</td><td>{{ $pct($p['best_pct']) }}</td>
                                <td>{{ $p['attempts'] }}</td><td>{{ $p['first_minutes'] !== null ? $p['first_minutes'] . ' min' : '—' }} / {{ $p['official'] ? $p['official'] . ' min' : __('not recorded') }}</td></tr>
                        @endforeach
                        </tbody></table></div>
                    <div class="small">{{ __('Average of first attempts: :a over :n paper(s)', ['a' => $pct($m['papers']['first_avg']), 'n' => $m['papers']['first_n']]) }}
                        @if ($m['papers']['trend'] !== null) · {{ __('latest vs previous paper: :d points', ['d' => ($m['papers']['trend'] >= 0 ? '+' : '') . $m['papers']['trend']]) }} @endif</div>
                @else
                    <div class="small text-muted">{{ __('No complete-paper attempts yet.') }}</div>
                @endif
                <h6 class="mt-3">{{ __('Question-solving time') }}</h6>
                <div class="small">{{ $m['time_change'] ? __('Across :n repeated question(s), the latest attempt took on average :m min :how than the first.', ['n' => $m['time_change']['questions'], 'm' => abs($m['time_change']['avg_change']), 'how' => $m['time_change']['avg_change'] <= 0 ? __('less') : __('more')]) : __('Not enough data yet.') }}</div>
                <h6 class="mt-3">{{ __('Missed targets') }}</h6>
                @forelse ($m['missed'] as $x)<div class="small">{{ __(':label is behind the straight-line plan by :time.', ['label' => __($x['label']), 'time' => $h($x['behind_by'])]) }}</div>@empty<div class="small text-muted">{{ __('None, or no targets configured.') }}</div>@endforelse
                <h6 class="mt-3">{{ __('Workload forecast to the end date (:n days left)', ['n' => $m['remaining_days']]) }}</h6>
                @forelse ($m['forecast'] as $x)<div class="small">{{ __(':label: :rem remaining → :need (original plan :plan per week).', ['label' => __($x['label']), 'rem' => $h($x['remaining']), 'need' => $x['required_weekly'] === null ? __('end date passed') : __(':t per week needed', ['t' => $h($x['required_weekly'])]), 'plan' => $h($x['weekly_plan'])]) }}</div>
                @empty<div class="small text-muted">{{ __('Not enough data yet: planned hours are not configured.') }}</div>@endforelse
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ __('Recommended priorities') }}</h3></div>
        <ul class="list-group list-group-flush">
            @forelse ($report['recommendations'] as $r)<li class="list-group-item small">{{ $r['text'] }}</li>@empty<li class="list-group-item text-muted">{{ __('Nothing to flag.') }}</li>@endforelse
        </ul>
    </div>
@stop
