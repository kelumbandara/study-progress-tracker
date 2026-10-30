@extends('adminlte::page')

@section('title', __('Phone Use'))
@section('content_header')
    <h1>{{ __('Phone use') }}</h1>
@stop

@section('content')
    @php
        use App\Support\Duration;
        $catLabel = fn ($c) => __(['study' => 'Study apps', 'messaging' => 'Messaging', 'social' => 'Social media', 'video' => 'Video', 'games' => 'Games', 'browser' => 'Browser', 'other' => 'Other apps'][$c] ?? 'Other apps');
        $colors = ['study' => '#2da44e', 'messaging' => '#1f6feb', 'social' => '#d1242f', 'video' => '#e8a317', 'games' => '#8250df', 'browser' => '#6c757d', 'other' => '#adb5bd'];
        $hasData = $s['days_with_data'] > 0;
        $chartDays = collect($s['days'])->map(fn ($d) => ['date' => $d['date'], 'cats' => $d['total_minutes'] === null ? null : $d['categories']])->values();
    @endphp
    @include('partials.flash')

    <div class="alert alert-light border small mb-3">
        <i class="fas fa-lock"></i>
        {{ __('Only the name of each app and the minutes it was open are collected, once the student turns on "Usage access" in the ezexam phone app. Messages, links, videos watched and screen content are never read. A day the phone has not reported is shown as "no data", not as zero.') }}
    </div>

    <div class="mb-3">
        @foreach ([7, 14, 30] as $n)
            <a href="{{ route('phone-usage.index', ['days' => $n]) }}" class="btn btn-sm {{ $days === $n ? 'btn-primary' : 'btn-outline-primary' }}">{{ __(':n days', ['n' => $n]) }}</a>
        @endforeach
    </div>

    @unless ($hasData)
        <div class="card"><div class="card-body text-center py-5">
            <p class="lead mb-1"><i class="fas fa-mobile-alt"></i> {{ __('No phone use reported yet.') }}</p>
            <p class="text-muted mb-0">{{ __('Open the ezexam phone app → More → Phone use, turn on Usage access, and the minutes will appear here.') }}</p>
        </div></div>
    @else
        <div class="row">
            <div class="col-6 col-lg-3"><div class="info-box"><span class="info-box-icon bg-info"><i class="fas fa-calendar-check"></i></span>
                <div class="info-box-content"><span class="info-box-text">{{ __('Days reported') }}</span><span class="info-box-number" data-testid="pu-days">{{ $s['days_with_data'] }} / {{ $days }}</span></div></div></div>
            <div class="col-6 col-lg-3"><div class="info-box"><span class="info-box-icon bg-warning"><i class="fas fa-hourglass-half"></i></span>
                <div class="info-box-content"><span class="info-box-text">{{ __('Average per reported day') }}</span><span class="info-box-number" data-testid="pu-avg">{{ Duration::format($s['avg_per_logged_day']) }}</span></div></div></div>
            <div class="col-6 col-lg-3"><div class="info-box"><span class="info-box-icon bg-secondary"><i class="fas fa-clock"></i></span>
                <div class="info-box-content"><span class="info-box-text">{{ __('Today') }}</span>
                    <span class="info-box-number" data-testid="pu-today">{{ ($s['today']['total_minutes'] ?? null) !== null ? Duration::format($s['today']['total_minutes']) : __('no data') }}</span></div></div></div>
            <div class="col-6 col-lg-3"><div class="info-box"><span class="info-box-icon bg-success"><i class="fas fa-sync"></i></span>
                <div class="info-box-content"><span class="info-box-text">{{ __('Last update from the phone') }}</span>
                    <span class="info-box-number small">{{ \Carbon\Carbon::parse($s['last_sync'])->timezone(config('app.timezone'))->translatedFormat('d M H:i') }}</span></div></div></div>
        </div>

        <div class="row">
            <div class="col-12 col-lg-8"><div class="card"><div class="card-header"><h3 class="card-title">{{ __('Phone hours per day, by kind of app') }}</h3></div>
                <div class="card-body"><canvas id="chartPhone" height="200" role="img" aria-label="{{ __('Stacked bar chart of phone hours per day by kind of app') }}"></canvas></div></div></div>
            <div class="col-12 col-lg-4"><div class="card h-100"><div class="card-header"><h3 class="card-title">{{ __('Share of phone time') }}</h3></div>
                <div class="card-body">
                    @foreach ($s['categories'] as $cat => $min)
                        <div class="mb-2"><div class="d-flex justify-content-between small"><span>{{ $catLabel($cat) }}</span><span>{{ Duration::format($min) }} · {{ $s['total_minutes'] ? round($min / $s['total_minutes'] * 100) : 0 }}%</span></div>
                            <div class="progress" style="height:.6rem"><div class="progress-bar" style="width: {{ $s['total_minutes'] ? $min / $s['total_minutes'] * 100 : 0 }}%; background: {{ $colors[$cat] ?? '#adb5bd' }}"></div></div></div>
                    @endforeach
                </div></div></div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">{{ __('Apps used (:n days)', ['n' => $days]) }}</h3></div>
            <div class="card-body table-responsive p-0">
                <table class="table table-sm table-hover mb-0" data-testid="pu-apps">
                    <thead><tr><th>{{ __('App') }}</th><th>{{ __('Kind') }}</th><th>{{ __('Total') }}</th><th>{{ __('Days used') }}</th><th>{{ __('Average on days used') }}</th></tr></thead>
                    <tbody>
                    @foreach (array_slice($s['top_apps'], 0, 25) as $a)
                        <tr><td>{{ $a['label'] }}</td><td>{{ $catLabel($a['category']) }}</td><td>{{ Duration::format($a['minutes']) }}</td><td>{{ $a['days'] }}</td><td>{{ Duration::format((int) round($a['minutes'] / max(1, $a['days']))) }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">{{ __('Day by day') }}</h3></div>
            <div class="card-body table-responsive p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Total') }}</th><th>{{ __('Most used') }}</th></tr></thead>
                    <tbody>
                    @foreach (array_reverse($s['days']) as $d)
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($d['date'])->translatedFormat('D d M') }}</td>
                            @if ($d['total_minutes'] === null)
                                <td colspan="2" class="text-muted">{{ __('no data') }}</td>
                            @else
                                <td>{{ Duration::format($d['total_minutes']) }}</td>
                                <td class="small">{{ collect($d['apps'])->take(3)->map(fn ($a) => $a['label'] . ' ' . Duration::format($a['minutes']))->join(' · ') }}</td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <form method="POST" action="{{ route('phone-usage.destroy') }}" class="mb-4"
              onsubmit="return window.confirm({{ Js::from(__('Erase all phone-use data that the phone has sent? This cannot be undone. The phone app will send new data again if sharing is still on.')) }});">
            @csrf @method('DELETE')
            <input type="hidden" name="confirm" value="erase">
            <button class="btn btn-outline-danger btn-sm"><i class="fas fa-trash"></i> {{ __('Erase all phone-use data') }}</button>
        </form>
    @endunless
@stop

@section('js')
@if ($hasData)
@php
    $chartText = ['cats' => collect($colors)->map(fn ($c, $k) => ['label' => $catLabel($k), 'color' => $c]), 'unit' => __('hours')];
@endphp
<script>
    (function () {
        if (typeof Chart === 'undefined') return;
        const days = @json($chartDays);
        const T = @json($chartText);
        const order = Object.keys(T.cats);
        new Chart(document.getElementById('chartPhone'), {
            type: 'bar',
            data: {
                labels: days.map(d => d.date.slice(5)),
                datasets: order.map(k => ({ label: T.cats[k].label, backgroundColor: T.cats[k].color,
                    data: days.map(d => d.cats === null ? null : +(((d.cats[k] || 0) / 60).toFixed(2))) }))
            },
            options: { scales: { xAxes: [{ stacked: true }], yAxes: [{ stacked: true, ticks: { beginAtZero: true }, scaleLabel: { display: true, labelString: T.unit } }] } }
        });
    })();
</script>
@endif
@stop
