@extends('adminlte::page')

@section('title', __('Dashboard'))
@section('content_header')
    <h1>{{ __('Dashboard') }}</h1>
@stop

@section('content')
    @php
    use App\Support\Duration;
    $chartText = ['planned' => __('Planned (h)'), 'actual' => __('Actual (h)'), 'week' => __('Week of :w'), 'avg' => __('Average score (%)'), 'cats' => collect(\App\Support\Category::LABELS)->map(fn ($l) => __($l)), 'subj' => $summaries ? collect($summaries)->mapWithKeys(fn ($x) => [$x['subject']->name => __($x['subject']->name)]) : []];
@endphp
    @include('partials.flash')

    @if ($sampleLoaded)
        <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap" role="alert">
            <span><i class="fas fa-flask"></i> <strong>{{ __('Sample data is loaded.') }}</strong> {{ __('Some numbers below are made up for demonstration and are labelled SAMPLE.') }}</span>
            <a href="{{ route('settings.edit') }}#sample" class="btn btn-sm btn-info mt-1 mt-md-0">{{ __('Remove it in Settings') }}</a>
        </div>
    @endif

    @if (! $todayLogged)
        <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap" role="alert">
            <span><i class="fas fa-exclamation-circle"></i> {{ __("Today's daily log has not been entered yet.") }}</span>
            <a href="{{ route('daily-log.index') }}" class="btn btn-sm btn-warning mt-1 mt-md-0">{{ __('Enter it now') }}</a>
        </div>
    @endif

    <div class="row mb-3">
        @foreach ([
            ['Enter Daily Log', 'daily-log.index', 'fa-calendar-check', 'primary'],
            ['Record Question', 'attempts.create', 'fa-pen', 'info'],
            ['Record Theory', 'theory.index', 'fa-book-reader', 'success'],
            ['Update Syllabus', 'syllabus.index', 'fa-list-ul', 'secondary'],
            ['Review Mistakes', 'mistakes.index', 'fa-redo', 'warning'],
            ['Settings', 'settings.edit', 'fa-cog', 'dark'],
        ] as [$label, $route, $icon, $color])
            <div class="col-6 col-md-4 col-xl-2 mb-2"><a href="{{ route($route) }}" class="btn btn-{{ $color }} btn-block py-3"><i class="fas {{ $icon }} d-block mb-1"></i>{{ __($label) }}</a></div>
        @endforeach
    </div>

    <div class="row">
        <div class="col-6 col-lg-3"><div class="info-box"><span class="info-box-icon bg-info"><i class="far fa-calendar-alt"></i></span>
            <div class="info-box-content"><span class="info-box-text">{{ __('Study period') }}</span>
                <span class="info-box-number small">{{ $period['start']->translatedFormat('d M Y') }} → {{ $period['end']->translatedFormat('d M Y') }}</span></div></div></div>
        <div class="col-6 col-lg-3"><div class="info-box"><span class="info-box-icon bg-secondary"><i class="fas fa-hourglass-half"></i></span>
            <div class="info-box-content"><span class="info-box-text">{{ __('Days elapsed') }}</span>
                <span class="info-box-number">{{ $period['elapsed_days'] }} / {{ $period['total_days'] }}</span>
                <span class="progress-description">{{ __(':pct% of period', ['pct' => $period['percent_elapsed']]) }}</span></div></div></div>
        <div class="col-6 col-lg-3"><div class="info-box"><span class="info-box-icon bg-warning"><i class="fas fa-flag-checkered"></i></span>
            <div class="info-box-content"><span class="info-box-text">{{ __('Days remaining') }}</span>
                <span class="info-box-number">{{ $period['remaining_days'] }}</span>
                @if ($period['ended'])<span class="progress-description">{{ __('Period has ended') }}</span>
                @elseif ($period['not_started'])<span class="progress-description">{{ __('Not started yet') }}</span>@endif</div></div></div>
        <div class="col-6 col-lg-3"><div class="info-box"><span class="info-box-icon bg-success"><i class="fas fa-user-clock"></i></span>
            <div class="info-box-content"><span class="info-box-text">{{ __('Independent study') }}</span>
                <span class="info-box-number">{{ Duration::hours($totals['independent_actual']) }} h</span>
                <span class="progress-description">{{ __('Theory + papers only') }}</span></div></div></div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="row text-center">
                <div class="col-4"><div class="text-muted small">{{ __('Total planned') }}</div>
                    <div class="h5 mb-0">{{ $totals['planned'] !== null ? Duration::hours($totals['planned']) . ' h' : __('Not Configured') }}</div></div>
                <div class="col-4"><div class="text-muted small">{{ __('Total actual (all 4 categories)') }}</div>
                    <div class="h5 mb-0">{{ Duration::hours($totals['actual_all']) }} h</div></div>
                <div class="col-4"><div class="text-muted small">{{ __('Planned remaining') }}</div>
                    <div class="h5 mb-0">{{ $totals['remaining'] !== null ? Duration::hours($totals['remaining']) . ' h' : '—' }}</div></div>
            </div>
            @if ($totals['planned'])
                <hr>
                <p class="mb-1 small">{{ __('Overall progress: :pct% of planned hours done (:done of :total h) · expected by today on a straight-line plan: :expected h (:elapsed% of the period elapsed).', ['pct' => $totals['percent'], 'done' => Duration::hours($totals['actual_configured']), 'total' => Duration::hours($totals['planned']), 'expected' => Duration::hours($totals['expected']), 'elapsed' => $period['percent_elapsed']]) }}</p>
                <div class="progress" style="height:1.1rem; position:relative">
                    <div class="progress-bar bg-primary" role="progressbar" style="width: {{ min(100, $totals['percent']) }}%" aria-valuenow="{{ $totals['percent'] }}" aria-valuemin="0" aria-valuemax="100">{{ $totals['percent'] >= 10 ? $totals['percent'] . '%' : '' }}</div>
                </div>
                <div class="small text-muted mt-1">{{ __('Expected marker: :pct% of the period has elapsed.', ['pct' => $period['percent_elapsed']]) }}</div>
            @endif
            @if ($totals['configured_count'] < 4)
                <p class="small text-muted mt-3 mb-0">
                    {{ __(':n of 4 categories have no planned hours yet. Planned totals and percentages use configured categories only.', ['n' => 4 - $totals['configured_count']]) }}
                    <a href="{{ route('settings.edit') }}">{{ __('Set planned hours') }}</a>.
                </p>
            @endif
        </div>
    </div>

    <div class="row">
        @foreach ($categories as $c)
            <div class="col-12 col-md-6 col-xl-3 mb-3">@include('partials.category-card', ['c' => $c])</div>
        @endforeach
    </div>

    {{-- Recommendations --}}
    <div class="card card-outline card-primary">
        <div class="card-header"><h3 class="card-title">{{ __('Recommendations') }}</h3></div>
        <ul class="list-group list-group-flush">
            @php $icons = ['warn' => ['fa-exclamation-triangle', __('Attention')], 'danger' => ['fa-times-circle', __('Review needed')], 'good' => ['fa-check-circle', __('Good')], 'info' => ['fa-info-circle', __('Note')]]; @endphp
            @forelse ($recommendations as $r)
                <li class="list-group-item"><i class="fas {{ $icons[$r['level']][0] }}"></i> <strong>{{ $icons[$r['level']][1] }}:</strong> {{ $r['text'] }}</li>
            @empty
                <li class="list-group-item text-muted">{{ __('Nothing to flag right now.') }}</li>
            @endforelse
        </ul>
        <div class="card-footer small text-muted">{{ __('Based only on recorded data. This is guidance, not a prediction of any exam result.') }}</div>
    </div>

    {{-- Subject dashboard --}}
    <h4 class="mt-4">{{ __('Subjects') }}</h4>
    <div class="row">
        @foreach ($summaries as $s)
            @php
                $sub = $s['subject']; $sy = $s['syllabus'];
                $prio = __(\App\Models\Subject::PRIORITIES[$sub->priority]);
                $pct = fn ($v) => $v === null ? '—' : $v . '%';
            @endphp
            <div class="col-12 col-xl-4 mb-3">
                <div class="card card-outline card-{{ $sub->priority === 'high' ? 'danger' : ($sub->priority === 'medium' ? 'warning' : 'success') }} h-100">
                    <div class="card-header">
                        <h3 class="card-title font-weight-bold">{{ __($sub->name) }}</h3>
                        <div class="card-tools"><span class="badge badge-light border">{{ __('Priority:') }} {{ $prio }}</span></div>
                    </div>
                    <div class="card-body">
                        <p class="mb-1 small text-muted">{{ __('Syllabus (:n items)', ['n' => $sy['total']]) }}</p>
                        <div class="progress mb-1" style="height:.9rem"><div class="progress-bar bg-primary" style="width: {{ $sy['theory_pct'] ?? 0 }}%">{{ $pct($sy['theory_pct']) }} {{ __('theory') }}</div></div>
                        <div class="progress mb-2" style="height:.9rem"><div class="progress-bar bg-success" style="width: {{ $sy['mastered_pct'] ?? 0 }}%">{{ $pct($sy['mastered_pct']) }} {{ __('mastered') }}</div></div>
                        <dl class="row mb-2 small">
                            <dt class="col-7">{{ __('Theory completed') }}</dt><dd class="col-5 text-right">{{ $sy['theory_completed'] }} / {{ $sy['total'] }}</dd>
                            <dt class="col-7">{{ __('Questions attempted') }}</dt><dd class="col-5 text-right">{{ $s['questions_attempted'] }} ({{ __(':n attempts', ['n' => $s['attempts']]) }})</dd>
                            <dt class="col-7">{{ __('Marks earned / available') }}</dt><dd class="col-5 text-right">{{ $s['marks_earned'] + 0 }} / {{ $s['marks_available'] + 0 }}</dd>
                            <dt class="col-7">{{ __('Average score') }}</dt>
                            <dd class="col-5 text-right">{{ $pct($s['weighted_pct']) }}</dd>
                            <dt class="col-7">{{ __('First attempts') }}</dt><dd class="col-5 text-right">{{ $pct($s['first']['pct']) }} (n={{ $s['first']['n'] }})</dd>
                            <dt class="col-7">{{ __('Retries') }}</dt><dd class="col-5 text-right">{{ $pct($s['retry']['pct']) }} (n={{ $s['retry']['n'] }})</dd>
                            <dt class="col-7">{{ __('Independent / assisted') }}</dt><dd class="col-5 text-right">{{ $pct($s['independent']['pct']) }} / {{ $pct($s['assisted']['pct']) }}</dd>
                            <dt class="col-7">{{ __('Latest timed paper') }}</dt>
                            <dd class="col-5 text-right">{{ $s['latest_timed'] ? $pct($s['latest_timed']['pct']) : '—' }}</dd>
                            <dt class="col-7">{{ __('Latest full paper') }}</dt>
                            <dd class="col-5 text-right">{{ $s['latest_paper'] ? $pct($s['latest_paper']['pct']) : '—' }} @if ($s['paper_count'])<span class="text-muted">({{ __(':n paper(s)', ['n' => $s['paper_count']]) }})</span>@endif</dd>
                            <dt class="col-7">{{ __('Paper-working time') }}</dt><dd class="col-5 text-right">{{ Duration::format($s['paper_minutes']) }}</dd>
                            <dt class="col-7">{{ __('Revision backlog') }}</dt><dd class="col-5 text-right">{{ $s['revision_backlog'] }} @if ($s['repeated'])<span class="badge badge-danger">{{ __(':n repeated', ['n' => $s['repeated']]) }}</span>@endif</dd>
                            <dt class="col-7">{{ __('Trend') }}</dt>
                            <dd class="col-5 text-right">
                                @if ($s['trend']){{ $s['trend']['delta'] > 0 ? '▲ +' : ($s['trend']['delta'] < 0 ? '▼ ' : '■ ') }}{{ $s['trend']['delta'] }} {{ __('pts') }} <span class="text-muted">{{ __('(last 5 vs previous :n)', ['n' => $s['trend']['n_previous']]) }}</span>
                                @else {{ __('Not enough data yet') }} @endif
                            </dd>
                        </dl>
                        <p class="mb-1 small"><strong>{{ __('Weak topics:') }}</strong>
                            @forelse ($s['weak_topics'] as $w){{ $w['name'] }} ({{ $w['pct'] }}%, n={{ $w['n'] }}){{ $loop->last ? '' : ', ' }}@empty
                                {{ $s['enough_data'] ? __('none below :pct%', ['pct' => $sub->weak_score_pct]) : __('Not enough data yet') }}@endforelse
                        </p>
                        <p class="mb-0 small text-muted">{{ __('Average score = total marks earned ÷ total marks available across all attempts. First attempts and retries are shown separately.') }}</p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Consistency --}}
    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ __('Study consistency') }}</h3></div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col-6 col-md-3 mb-2"><div class="text-muted small">{{ __('Days logged') }}</div><div class="h5 mb-0">{{ $consistency['days_logged'] }} / {{ $consistency['days_in_range'] }}</div></div>
                <div class="col-6 col-md-3 mb-2"><div class="text-muted small">{{ __('Missing logs') }}</div><div class="h5 mb-0">{{ $consistency['days_missing'] }}</div><div class="small text-muted">{{ __('not counted as zero') }}</div></div>
                <div class="col-6 col-md-3 mb-2"><div class="text-muted small">{{ __('Days with independent study') }}</div><div class="h5 mb-0">{{ $consistency['days_with_independent'] }}</div></div>
                <div class="col-6 col-md-3 mb-2"><div class="text-muted small">{{ __('Planned sessions') }}</div><div class="h5 mb-0">{{ __('Not configured') }}</div></div>
                <div class="col-6 col-md-3"><div class="text-muted small">{{ __('Average per logged day') }}</div><div class="h6 mb-0">{{ $consistency['avg_daily_minutes'] !== null ? Duration::format($consistency['avg_daily_minutes']) : __('Not enough data yet') }}</div></div>
                <div class="col-6 col-md-3"><div class="text-muted small">{{ __('…independent only') }}</div><div class="h6 mb-0">{{ $consistency['avg_daily_independent'] !== null ? Duration::format($consistency['avg_daily_independent']) : '—' }}</div></div>
                <div class="col-6 col-md-3"><div class="text-muted small">{{ __('Typical week (logged days × 7)') }}</div><div class="h6 mb-0">{{ $consistency['avg_weekly_minutes'] !== null ? Duration::format($consistency['avg_weekly_minutes']) : '—' }}</div></div>
                <div class="col-6 col-md-3"><div class="text-muted small">{{ __('…independent only') }}</div><div class="h6 mb-0">{{ $consistency['avg_weekly_independent'] !== null ? Duration::format($consistency['avg_weekly_independent']) : '—' }}</div></div>
            </div>
        </div>
    </div>

    {{-- Phone use (reported by the phone app) --}}
    <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-mobile-alt"></i> {{ __('Phone use (last 7 days)') }}</h3>
            <div class="card-tools"><a href="{{ route('phone-usage.index') }}" class="btn btn-tool">{{ __('Details') }} <i class="fas fa-arrow-right"></i></a></div></div>
        <div class="card-body">
            @if ($phone['days_with_data'] === 0)
                <p class="text-muted mb-0">{{ __('No phone use reported yet.') }} {{ __('Open the ezexam phone app → More → Phone use, turn on Usage access, and the minutes will appear here.') }}</p>
            @else
                <div class="row text-center mb-2">
                    <div class="col-4"><div class="text-muted small">{{ __('Today') }}</div><div class="h5 mb-0">{{ ($phone['today']['total_minutes'] ?? null) !== null ? Duration::format($phone['today']['total_minutes']) : __('no data') }}</div></div>
                    <div class="col-4"><div class="text-muted small">{{ __('Average per reported day') }}</div><div class="h5 mb-0">{{ Duration::format($phone['avg_per_logged_day']) }}</div></div>
                    <div class="col-4"><div class="text-muted small">{{ __('Days reported') }}</div><div class="h5 mb-0">{{ $phone['days_with_data'] }} / 7</div></div>
                </div>
                <div class="small">@foreach (array_slice($phone['top_apps'], 0, 5) as $a)<span class="badge badge-light border mr-1">{{ $a['label'] }} · {{ Duration::format($a['minutes']) }}</span>@endforeach</div>
            @endif
        </div>
    </div>

    {{-- Charts --}}
    <div class="row">
        <div class="col-12 col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">{{ __('Planned vs actual hours') }}</h3></div>
            <div class="card-body"><canvas id="chartHours" height="220" role="img" aria-label="{{ __('Bar chart of planned versus actual hours per category') }}"></canvas></div></div></div>
        <div class="col-12 col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">{{ __('Hours per week (logged days)') }}</h3></div>
            <div class="card-body">
                @if ($weekly)<canvas id="chartWeekly" height="220" role="img" aria-label="{{ __('Stacked bar chart of weekly hours by category') }}"></canvas>
                @else<p class="text-muted mb-0">{{ __('No daily logs yet.') }}</p>@endif
            </div></div></div>
        <div class="col-12"><div class="card"><div class="card-header"><h3 class="card-title">{{ __('Average score per week, by subject') }}</h3></div>
            <div class="card-body">
                @if ($scoreTrend)<canvas id="chartScores" height="140" role="img" aria-label="{{ __('Line chart of weekly average question score per subject') }}"></canvas>
                @else<p class="text-muted mb-0">{{ __('No question attempts yet.') }}</p>@endif
            </div></div></div>
    </div>
@stop

@section('js')
<script>
    (function () {
        if (typeof Chart === 'undefined') return;
        const L = @json($chartText);
        const cats = @json($chartCategories).map(c => Object.assign(c, { label: L.cats[c.key] || c.label }));
        new Chart(document.getElementById('chartHours'), {
            type: 'bar',
            data: { labels: cats.map(c => c.label), datasets: [
                { label: L.planned, data: cats.map(c => c.planned), backgroundColor: '#9aa5b1' },
                { label: L.actual, data: cats.map(c => c.actual), backgroundColor: '#1f6feb' } ] },
            options: { scales: { yAxes: [{ ticks: { beginAtZero: true } }] } }
        });

        const weekly = @json($weekly);
        const wk = Object.keys(weekly);
        if (wk.length) {
            const colors = { school: '#6c757d', tuition: '#e8a317', theory: '#1f6feb', papers: '#2da44e' };
            const names = L.cats;
            new Chart(document.getElementById('chartWeekly'), {
                type: 'bar',
                data: { labels: wk.map(w => L.week.replace(':w', w)), datasets: Object.keys(names).map(k => ({ label: names[k], backgroundColor: colors[k], data: wk.map(w => +(((weekly[w][k] || 0) / 60).toFixed(1))) })) },
                options: { scales: { xAxes: [{ stacked: true }], yAxes: [{ stacked: true, ticks: { beginAtZero: true } }] } }
            });
        }

        const trend = @json($scoreTrend);
        const subjects = Object.keys(trend);
        if (subjects.length) {
            const weeks = Array.from(new Set(subjects.flatMap(s => Object.keys(trend[s])))).sort();
            const palette = ['#d1242f', '#e8a317', '#1f6feb', '#2da44e'];
            new Chart(document.getElementById('chartScores'), {
                type: 'line',
                data: { labels: weeks.map(w => L.week.replace(':w', w)), datasets: subjects.map((s, i) => ({
                    label: (L.subj && L.subj[s]) || s, borderColor: palette[i % palette.length], backgroundColor: 'transparent', spanGaps: true, pointStyle: ['circle', 'rect', 'triangle', 'rectRot'][i % 4],
                    data: weeks.map(w => trend[s][w] ?? null) })) },
                options: { scales: { yAxes: [{ ticks: { beginAtZero: true, max: 100 }, scaleLabel: { display: true, labelString: L.avg } }] } }
            });
        }
    })();
</script>
@stop
