@extends('adminlte::page')

@section('title', __('Settings'))
@section('content_header')
    <h1>{{ __('Settings') }}</h1>
@stop

@section('content')
    @php
        use App\Support\Duration;
        use App\Support\Category;
        use App\Models\Subject;
        $hoursValue = fn ($k, $c) => old("hours.$k", $c['planned'] !== null ? rtrim(rtrim(number_format($c['planned'] / 60, 2, '.', ''), '0'), '.') : '');
    @endphp

    @if (session('status'))
        <div class="alert alert-success" role="alert"><i class="fas fa-check-circle"></i> {{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <strong>{{ __('Please fix the following:') }}</strong>
            <ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('settings.update') }}" novalidate>
        @csrf @method('PUT')

        <div class="card card-primary card-outline">
            <div class="card-header"><h3 class="card-title">{{ __('Study period') }}</h3></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group col-12 col-md-4">
                        <label for="study_start">{{ __('Study start date') }}</label>
                        <input type="date" id="study_start" name="study_start" class="form-control @error('study_start') is-invalid @enderror"
                               value="{{ old('study_start', $settings->study_start->toDateString()) }}" required>
                    </div>
                    <div class="form-group col-12 col-md-4">
                        <label for="study_end">{{ __('Study end date') }}</label>
                        <input type="date" id="study_end" name="study_end" class="form-control @error('study_end') is-invalid @enderror"
                               value="{{ old('study_end', $settings->study_end->toDateString()) }}" required>
                    </div>
                    <div class="form-group col-12 col-md-4">
                        <label for="exam_date">{{ __('Target examination date') }} <small class="text-muted">{{ __('(optional)') }}</small></label>
                        <input type="date" id="exam_date" name="exam_date" class="form-control @error('exam_date') is-invalid @enderror"
                               value="{{ old('exam_date', $settings->exam_date?->toDateString()) }}">
                        <small class="form-text text-muted">{{ __('Leave blank until the exact date is announced (expected August 2027).') }}</small>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-12 col-md-4">
                        <label for="timezone">{{ __('Time zone') }}</label>
                        <select id="timezone" name="timezone" class="form-control @error('timezone') is-invalid @enderror">
                            @foreach ($timezones as $tz)
                                <option value="{{ $tz }}" @selected(old('timezone', $settings->timezone) === $tz)>{{ $tz }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-6 col-md-4">
                        <label for="paper_year_from">{{ __('Past papers: first year') }}</label>
                        <input type="number" id="paper_year_from" name="paper_year_from" class="form-control @error('paper_year_from') is-invalid @enderror"
                               value="{{ old('paper_year_from', $settings->paper_year_from) }}">
                    </div>
                    <div class="form-group col-6 col-md-4">
                        <label for="paper_year_to">{{ __('Past papers: last year') }}</label>
                        <input type="number" id="paper_year_to" name="paper_year_to" class="form-control @error('paper_year_to') is-invalid @enderror"
                               value="{{ old('paper_year_to', $settings->paper_year_to) }}">
                    </div>
                </div>

                <p class="mb-1">{!! __(':total calendar days in total · :elapsed elapsed (:pct%) · :remaining remaining', ['total' => $period['total_days'], 'elapsed' => $period['elapsed_days'], 'pct' => $period['percent_elapsed'], 'remaining' => $period['remaining_days']]) !!}</p>
                @if ($logsOutsidePeriod > 0)
                    <p class="text-warning-emphasis mb-0"><i class="fas fa-exclamation-triangle"></i>
                        {{ __(':n existing daily log(s) fall outside this period. They are kept and still counted in the totals.', ['n' => $logsOutsidePeriod]) }}</p>
                @endif
                <p class="small text-muted mb-0">{{ __('Changing the dates never deletes any recorded data.') }}</p>
            </div>
        </div>

        <div class="card card-primary card-outline">
            <div class="card-header"><h3 class="card-title">{{ __('Total planned hours for the whole period') }}</h3></div>
            <div class="card-body">
                <p class="text-muted">{!! __('Enter the total across the entire study period. Decimals are fine (e.g. 125.5). Leave blank for <em>Not Configured</em>. Daily and weekly rates are worked out automatically.') !!}</p>
                <div class="form-row">
                    @foreach ($categories as $k => $c)
                        <div class="form-group col-12 col-sm-6 col-lg-3">
                            <label for="hours_{{ $k }}">{{ __(':label (hours)', ['label' => __($c['label'])]) }}</label>
                            <input type="number" step="0.01" min="0" inputmode="decimal" id="hours_{{ $k }}" name="hours[{{ $k }}]"
                                   class="form-control @error("hours.$k") is-invalid @enderror" value="{{ $hoursValue($k, $c) }}" placeholder="{{ __('Not Configured') }}">
                            <small class="form-text text-muted">
                                @if ($c['configured'])
                                    {{ __('≈ :w h/week · :d h/day', ['w' => Duration::hours((int) round($c['weekly_plan'])), 'd' => Duration::hours((int) round($c['daily_plan']))]) }}
                                    @if ($c['revised']) <br>{{ __('Original: :o h → revised', ['o' => Duration::hours($c['original'])]) }} @endif
                                @else
                                    {{ __('Not configured') }}
                                @endif
                            </small>
                        </div>
                    @endforeach
                </div>
                <div class="form-group mb-0">
                    <label for="reason">{{ __('Reason for changing planned hours') }} <small class="text-muted">{{ __('(optional, kept in history)') }}</small></label>
                    <input type="text" id="reason" name="reason" maxlength="255" class="form-control" value="{{ old('reason') }}">
                </div>
            </div>
        </div>

        <div class="card card-primary card-outline">
            <div class="card-header"><h3 class="card-title">{{ __('Subjects') }}</h3></div>
            <div class="card-body">
                <p class="text-muted">{{ __('Priority affects recommendations and how subjects are presented. It does not change any planned hours.') }}</p>
                @foreach ($subjects as $s)
                    <div class="form-row border-bottom pb-2 mb-2">
                        <div class="col-12 col-md-3 mb-1"><strong>{{ __($s->name) }}</strong></div>
                        <div class="form-group col-12 col-md-3 mb-1">
                            <label class="small" for="prio_{{ $s->id }}">{{ __('Priority') }}</label>
                            <select id="prio_{{ $s->id }}" name="subjects[{{ $s->id }}][priority]" class="form-control form-control-sm">
                                @foreach (Subject::PRIORITIES as $v => $label)
                                    <option value="{{ $v }}" @selected(old("subjects.$s->id.priority", $s->priority) === $v)>{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-6 col-md-3 mb-1">
                            <label class="small" for="weak_{{ $s->id }}">{{ __('Weak score below (%)') }}</label>
                            <input type="number" min="1" max="100" id="weak_{{ $s->id }}" name="subjects[{{ $s->id }}][weak_score_pct]"
                                   class="form-control form-control-sm" value="{{ old("subjects.$s->id.weak_score_pct", $s->weak_score_pct) }}">
                        </div>
                        <div class="form-group col-6 col-md-3 mb-1">
                            <label class="small" for="slow_{{ $s->id }}">{{ __('Slow above (min per mark)') }}</label>
                            <input type="number" step="0.01" min="0" id="slow_{{ $s->id }}" name="subjects[{{ $s->id }}][slow_minutes_per_mark]"
                                   class="form-control form-control-sm" placeholder="{{ __('Not set') }}" value="{{ old("subjects.$s->id.slow_minutes_per_mark", $s->slow_minutes_per_mark) }}">
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-lg btn-block d-md-inline-block w-md-auto"><i class="fas fa-save"></i> {{ __('Save settings') }}</button>
    </form>

    <div class="card card-outline card-info mt-4" id="sample">
        <div class="card-header"><h3 class="card-title">{{ __('Sample data (for trying the app)') }}</h3></div>
        <div class="card-body">
            <p class="mb-2">{!! __('Sample data fills the dashboard and reports with <strong>made-up</strong> units, papers, marks and logs so you can see how everything works. It is not the real syllabus or real exam papers, and every sample record is labelled <span class="badge badge-info">SAMPLE</span>. Your planned hours are not changed.') !!}</p>
            @if ($sample['exists'])
                @php $c = $sample['counts']; @endphp
                <p class="mb-2 small">{{ __('Currently loaded: :u sample units, :p papers, :q questions, :a attempts, :l daily logs.', ['u' => $c['units'], 'p' => $c['papers'], 'q' => $c['questions'], 'a' => $c['attempts'], 'l' => $c['logs']]) }}</p>
                <form method="POST" action="{{ route('sample-data.destroy') }}"
                      onsubmit="return window.confirm({{ Js::from(__("Remove all sample data?\n\nThis permanently deletes :u sample units, :p papers, :q questions, :a attempts and :l daily logs.\nIt also deletes any attempts you recorded on SAMPLE questions.\nYour own records are not touched.", ['u' => $c['units'], 'p' => $c['papers'], 'q' => $c['questions'], 'a' => $c['attempts'], 'l' => $c['logs']])) }});">
                    @csrf @method('DELETE')
                    <input type="hidden" name="confirm" value="remove">
                    <button class="btn btn-outline-danger"><i class="fas fa-trash"></i> {{ __('Remove all sample data') }}</button>
                </form>
            @else
                <form method="POST" action="{{ route('sample-data.store') }}">
                    @csrf
                    <button class="btn btn-outline-info"><i class="fas fa-flask"></i> {{ __('Load sample data') }}</button>
                </form>
            @endif
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header"><h3 class="card-title">{{ __('Planned-hours history') }}</h3></div>
        <div class="card-body table-responsive p-0">
            <table class="table table-sm mb-0">
                <thead><tr><th>{{ __('When') }}</th><th>{{ __('Category') }}</th><th>{{ __('From') }}</th><th>{{ __('To') }}</th><th>{{ __('Reason') }}</th></tr></thead>
                <tbody>
                @forelse ($history as $h)
                    <tr>
                        <td>{{ $h->changed_at->timezone($settings->timezone)->translatedFormat('d M Y H:i') }}</td>
                        <td>{{ __(Category::LABELS[$h->category]) }}</td>
                        <td>{{ $h->old_minutes !== null ? Duration::hours($h->old_minutes) . ' h' : __('Not Configured') }}</td>
                        <td>{{ $h->new_minutes !== null ? Duration::hours($h->new_minutes) . ' h' : __('Not Configured') }}</td>
                        <td>{{ $h->reason ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-3">{{ __('No changes yet. Planned hours are not configured.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@stop
