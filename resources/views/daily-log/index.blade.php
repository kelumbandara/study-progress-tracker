@extends('adminlte::page')

@section('title', __('Daily Log'))
@section('content_header')
    <h1>{{ __('Daily Log') }}</h1>
@stop

@section('content')
    @php
        use App\Support\Category;
        use App\Support\Duration;
        $val = fn ($k) => $log?->{Category::column($k)};
        $hint = ['school' => __('Time at school'), 'tuition' => __('Time at tuition class'), 'theory' => __('Your own theory study'), 'papers' => __('Writing past papers / questions')];
    @endphp

    @include('partials.flash')

    <div class="row">
        <div class="col-12 col-lg-7">
            <div class="card card-primary card-outline">
                <div class="card-header"><h3 class="card-title">{{ __('Enter the day\'s time') }}</h3></div>
                <form method="POST" action="{{ route('daily-log.store') }}" id="logForm" novalidate>
                    @csrf
                    <div class="card-body">
                        <div class="form-group">
                            <label for="log_date">{{ __('Date') }}</label>
                            <input type="date" id="log_date" name="log_date" max="{{ $today }}" value="{{ old('log_date', $date) }}"
                                   class="form-control" onchange="location.href='{{ route('daily-log.index') }}?date='+this.value">
                            @if ($log)
                                <small class="text-success"><i class="fas fa-check-circle"></i> {{ __('Confirmed entry (last saved :when). Saving again edits it and keeps an audit trail.', ['when' => $log->updated_at->diffForHumans()]) }}</small>
                            @else
                                <small class="text-warning-emphasis"><i class="fas fa-exclamation-circle"></i> {{ __('Missing: nothing has been logged for this date yet. A missing day is not counted as zero. To record a zero day, save it with 0 h 0 min.') }}</small>
                            @endif
                        </div>

                        <p class="text-muted small mb-2">{{ __('Enter the total time for each category, in hours and minutes. No start or end times needed.') }}</p>
                        @foreach (Category::LABELS as $k => $label)
                            <div class="form-group">
                                <label for="{{ $k }}_h">{{ __($label) }} <small class="text-muted">— {{ $hint[$k] }}</small></label>
                                @include('partials.duration-input', ['name' => $k, 'minutes' => $val($k), 'label' => __($label)])
                            </div>
                        @endforeach

                        <div class="alert alert-light border py-2">{{ __('Day total:') }} <strong id="dayTotal">0 h 00 min</strong></div>

                        <div class="form-group">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="is_rest_day" name="is_rest_day" value="1" @checked(old('is_rest_day', $log?->is_rest_day))>
                                <label class="custom-control-label" for="is_rest_day">{{ __('Rest day / no school or tuition') }}</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="rating">{{ __('How was the day?') }} <small class="text-muted">{{ __('(optional: 1 hard/low motivation … 5 easy/high motivation)') }}</small></label>
                            <select id="rating" name="rating" class="form-control">
                                <option value="">—</option>
                                @for ($i = 1; $i <= 5; $i++)<option value="{{ $i }}" @selected((string) old('rating', $log?->rating) === (string) $i)>{{ $i }}</option>@endfor
                            </select>
                        </div>
                        <div class="form-group mb-0">
                            <label for="notes">{{ __('Notes') }} <small class="text-muted">{{ __('(optional)') }}</small></label>
                            <textarea id="notes" name="notes" rows="2" maxlength="2000" class="form-control">{{ old('notes', $log?->notes) }}</textarea>
                        </div>
                    </div>
                    <div class="card-footer"><button class="btn btn-primary btn-lg btn-block"><i class="fas fa-save"></i> {{ __('Save daily log') }}</button></div>
                </form>
            </div>

            @if ($warnings)
                @foreach ($warnings as $w)<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> {{ $w }}</div>@endforeach
            @endif
        </div>

        <div class="col-12 col-lg-5">
            <div class="card">
                <div class="card-header"><h3 class="card-title">{{ __('Cumulative totals') }}</h3></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        @foreach (Category::LABELS as $k => $label)
                            <tr><td>{{ __($label) }}</td><td class="text-right">{{ Duration::format($cumulative[$k]) }}</td></tr>
                        @endforeach
                        <tr class="font-weight-bold"><td>{{ __('All categories') }}</td><td class="text-right">{{ Duration::format(array_sum($cumulative)) }}</td></tr>
                    </table>
                </div>
                <div class="card-footer small text-muted">
                    {{ __(':missing of :days day(s) so far have no log. Missing days are not counted as zero.', ['missing' => $missing, 'days' => $daysSoFar]) }}
                </div>
            </div>

            @if ($audit->isNotEmpty())
                <div class="card">
                    <div class="card-header"><h3 class="card-title">{{ __('Change history for :date', ['date' => \Carbon\Carbon::parse($date)->translatedFormat('d M Y')]) }}</h3></div>
                    <ul class="list-group list-group-flush small">
                        @foreach ($audit as $a)
                            <li class="list-group-item">
                                <strong>{{ __(ucfirst($a->action)) }}</strong> · {{ $a->created_at->timezone(config('app.timezone'))->translatedFormat('d M H:i') }}
                                @if ($a->action === 'updated')
                                    <br>@foreach (($a->new_values ?? []) as $f => $nv)
                                        {{ str_replace('_', ' ', $f) }}: {{ is_null($a->old_values[$f] ?? null) ? '—' : $a->old_values[$f] }} → {{ is_null($nv) ? '—' : $nv }}@if (! $loop->last); @endif
                                    @endforeach
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ __('Recent logs') }}</h3></div>
        <div class="card-body table-responsive p-0">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('School') }}</th><th>{{ __('Tuition') }}</th><th>{{ __('Theory') }}</th><th>{{ __('Papers') }}</th><th>{{ __('Day total') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($logs as $l)
                    @php $tot = $l->school_minutes + $l->tuition_minutes + $l->theory_minutes + $l->papers_minutes; @endphp
                    <tr>
                        <td>{{ $l->log_date->translatedFormat('D d M Y') }} @if ($l->is_rest_day)<span class="badge badge-secondary">{{ __('Rest day') }}</span>@endif @if ($l->is_sample)<span class="badge badge-info">{{ __('SAMPLE') }}</span>@endif</td>
                        <td>{{ Duration::format($l->school_minutes) }}</td><td>{{ Duration::format($l->tuition_minutes) }}</td>
                        <td>{{ Duration::format($l->theory_minutes) }}</td><td>{{ Duration::format($l->papers_minutes) }}</td>
                        <td>{{ $tot === 0 ? __('0 (confirmed zero)') : Duration::format($tot) }}</td>
                        <td><a href="{{ route('daily-log.index', ['date' => $l->log_date->toDateString()]) }}">{{ __('Edit') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-3">{{ __('No daily logs yet. Enter today\'s time above.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($logs->hasPages())<div class="card-footer">{{ $logs->links() }}</div>@endif
    </div>
@stop

@section('js')
<script>
    (function () {
        const names = ['school', 'tuition', 'theory', 'papers'];
        function total() {
            let t = 0;
            names.forEach(n => { t += (parseInt(document.getElementById(n + '_h').value) || 0) * 60 + (parseInt(document.getElementById(n + '_m').value) || 0); });
            document.getElementById('dayTotal').textContent = Math.floor(t / 60) + ' h ' + String(t % 60).padStart(2, '0') + ' min' + (t > 1440 ? '  ' + @json(__('(more than 24 h!)')) : '');
        }
        document.querySelectorAll('.duration-part').forEach(i => i.addEventListener('input', total));
        total();
    })();
</script>
@stop
