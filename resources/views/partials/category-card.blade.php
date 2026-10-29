@php
    use App\Support\Duration;
    $badge = [
        'not_configured' => ['secondary', __('Not Configured')],
        'on_track' => ['success', __('On track')],
        'behind' => ['warning', __('Behind plan')],
        'complete' => ['primary', __('Complete')],
        'overrun' => ['info', __('Over plan')],
    ][$c['status']];
    $pct = $c['percent'] ?? 0;
@endphp
<div class="card card-outline card-{{ $badge[0] }} h-100">
    <div class="card-header">
        <h3 class="card-title font-weight-bold">{{ __($c['label']) }}</h3>
        <div class="card-tools"><span class="badge badge-{{ $badge[0] }}">{{ $badge[1] }}</span></div>
    </div>
    <div class="card-body">
        <dl class="row mb-2">
            <dt class="col-6">{{ __('Planned') }}</dt>
            <dd class="col-6 text-right text-nowrap">{{ $c['configured'] ? Duration::hours($c['planned']) . ' h' : __('Not Configured') }}</dd>
            <dt class="col-7">{{ __('Actual') }}</dt>
            <dd class="col-5 text-right">{{ Duration::hours($c['actual']) }} h</dd>
            <dt class="col-7">{{ __('Remaining') }}</dt>
            <dd class="col-5 text-right">{{ $c['configured'] ? Duration::hours($c['remaining']) . ' h' : '—' }}</dd>
            @if ($c['overrun'] > 0)
                <dt class="col-7">{{ __('Over plan by') }}</dt>
                <dd class="col-5 text-right">{{ Duration::hours($c['overrun']) }} h</dd>
            @endif
            <dt class="col-7">{{ __('Needed per week') }}</dt>
            <dd class="col-5 text-right">{{ $c['required_weekly'] !== null ? Duration::hours((int) round($c['required_weekly'])) . ' h' : '—' }}</dd>
        </dl>
        <div class="progress" style="height: 1.1rem">
            <div class="progress-bar bg-{{ $badge[0] }}" role="progressbar" style="width: {{ min(100, $pct) }}%"
                 aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100">{{ $c['percent'] !== null && $c['percent'] >= 10 ? $c['percent'] . '%' : '' }}</div>
        </div>
        @if ($c['percent'] !== null && $c['percent'] < 10)<div class="small text-muted mt-1">{{ __(':pct% done', ['pct' => $c['percent']]) }}</div>@endif
        @foreach ($c['warnings'] as $w)
            <p class="text-warning-emphasis small mt-2 mb-0"><i class="fas fa-exclamation-triangle"></i> {{ $w }}</p>
        @endforeach
    </div>
</div>
