@extends('adminlte::page')

@section('title', __('Syllabus'))
@section('content_header')
    <h1>{{ __('Syllabus') }}</h1>
@stop

@section('content')
    @php
    @endphp

    @include('partials.flash')

    <ul class="nav nav-pills mb-3">
        @foreach ($subjects as $s)
            <li class="nav-item"><a class="nav-link {{ $s->id === $subject->id ? 'active' : '' }}" href="{{ route('syllabus.index', ['subject' => $s->id]) }}">{{ __($s->name) }}</a></li>
        @endforeach
    </ul>

    @if ($hasSample)
        <div class="alert alert-info"><i class="fas fa-info-circle"></i> {!! __('Items marked SAMPLE are sample data, not the official syllabus.') !!}</div>
    @endif

    {{-- Coverage vs mastery --}}
    <div class="row">
        @foreach ([
            [__('Topics'), $subjectStats['total'], __('items in this subject'), 'bg-secondary'],
            [__('Theory completed'), $subjectStats['theory_completed'] . ' / ' . $subjectStats['total'], __(':pct% coverage', ['pct' => $subjectStats['theory_pct'] ?? 0]), 'bg-primary'],
            [__('Practised'), $subjectStats['practised'], __(':n with no practice questions', ['n' => $subjectStats['not_practised']]), 'bg-indigo'],
            [__('Mastered'), $subjectStats['mastered'] . ' / ' . $subjectStats['total'], __(':pct% demonstrated mastery', ['pct' => $subjectStats['mastered_pct'] ?? 0]), 'bg-success'],
            [__('Needs revision'), $subjectStats['revision'], __('topics flagged'), 'bg-warning'],
        ] as [$t, $v, $d, $c])
            <div class="col-6 col-lg"><div class="info-box"><div class="info-box-content"><span class="info-box-text">{{ $t }}</span>
                <span class="info-box-number">{{ $v }}</span><span class="progress-description small">{{ $d }}</span></div></div></div>
        @endforeach
    </div>

    @forelse ($units as $unit)
        @php $us = $unitStats[$unit->id]; @endphp
        <div class="card card-outline card-primary collapsed-card">
            <div class="card-header" data-card-widget="collapse" style="cursor:pointer">
                <h3 class="card-title">
                    {{ $unit->name }} @if ($unit->reference)<small class="text-muted">({{ $unit->reference }})</small>@endif
                    @if ($unit->is_sample)<span class="badge badge-info">{{ __('SAMPLE') }}</span>@endif
                </h3>
                <div class="card-tools">
                    <span class="badge badge-light border">{{ __(':a/:b theory', ['a' => $us['theory_completed'], 'b' => $us['total']]) }}</span>
                    <span class="badge badge-light border">{{ __(':n mastered', ['n' => $us['mastered']]) }}</span>
                    <button type="button" class="btn btn-tool" data-card-widget="collapse" aria-label="{{ __('Expand or collapse') }}"><i class="fas fa-plus"></i></button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="px-3 pt-2"><div class="progress" style="height:.7rem"><div class="progress-bar bg-primary" style="width: {{ $us['theory_pct'] ?? 0 }}%"></div></div>
                    <small class="text-muted">{{ __(':p% theory · :r remaining · :n not practised', ['p' => $us['theory_pct'] ?? 0, 'r' => $us['remaining'], 'n' => $us['not_practised']]) }}</small></div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>{{ __('Topic') }}</th><th>{{ __('Status') }}</th><th class="text-nowrap">{{ __('Confidence') }}</th><th>{{ __('Update') }}</th></tr></thead>
                        <tbody>
                        @foreach (($topics[$unit->id] ?? collect()) as $t)
                            @include('syllabus._row', ['t' => $t, 'depth' => 0])
                            @foreach (($children[$t->id] ?? collect()) as $c)
                                @include('syllabus._row', ['t' => $c, 'depth' => 1])
                            @endforeach
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <form method="POST" action="{{ route('syllabus.topics.store') }}" class="form-inline p-3 border-top">
                    @csrf <input type="hidden" name="unit_id" value="{{ $unit->id }}">
                    <input type="text" name="name" class="form-control form-control-sm mr-2 mb-1" placeholder="{{ __('New topic or subtopic name') }}" required maxlength="255">
                    <select name="parent_id" class="form-control form-control-sm mr-2 mb-1">
                        <option value="">{{ __('Top-level topic') }}</option>
                        @foreach (($topics[$unit->id] ?? collect()) as $t)<option value="{{ $t->id }}">{{ __('Subtopic of: :n', ['n' => \Illuminate\Support\Str::limit($t->name, 40)]) }}</option>@endforeach
                    </select>
                    <input type="text" name="reference" class="form-control form-control-sm mr-2 mb-1" placeholder="{{ __('Syllabus ref (optional)') }}" maxlength="64">
                    <button class="btn btn-sm btn-outline-primary mb-1"><i class="fas fa-plus"></i> {{ __('Add') }}</button>
                </form>
            </div>
        </div>
    @empty
        <div class="card"><div class="card-body text-center py-5">
            <p class="lead mb-1">{{ __('No syllabus units for :s yet.', ['s' => __($subject->name)]) }}</p>
            <p class="text-muted">{{ __('Load the NIE syllabus below, import a CSV/Excel file, or add units by hand.') }}</p>
        </div></div>
    @endforelse

    <div class="row">
        <div class="col-12 col-lg-6">
            <div class="card"><div class="card-header"><h3 class="card-title">{{ __('Add a unit manually') }}</h3></div>
                <form method="POST" action="{{ route('syllabus.units.store') }}" class="card-body">
                    @csrf <input type="hidden" name="subject_id" value="{{ $subject->id }}">
                    <div class="form-group"><label for="unit_name">{{ __('Unit / lesson name') }}</label><input id="unit_name" name="name" class="form-control" required maxlength="255"></div>
                    <div class="form-group"><label for="unit_ref">{{ __('Official reference (optional)') }}</label><input id="unit_ref" name="reference" class="form-control" maxlength="64"></div>
                    <button class="btn btn-primary"><i class="fas fa-plus"></i> {{ __('Add unit to :s', ['s' => __($subject->name)]) }}</button>
                </form>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card"><div class="card-header"><h3 class="card-title">{{ __('Import syllabus') }}</h3></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('syllabus.load-nie') }}" class="mb-3">
                        @csrf
                        <button class="btn btn-success btn-block"><i class="fas fa-download"></i> {{ __('Load NIE syllabus (Combined Maths, Physics, IT)') }}</button>
                        <small class="text-muted d-block mt-1">{{ __('Unit and competency-level names taken from the official NIE syllabus PDFs (Grades 12 and 13, effective 2017); each row keeps its source link and level number. Safe to click twice: existing rows are skipped.') }}</small>
                    </form>
                    <form method="POST" action="{{ route('syllabus.import') }}" enctype="multipart/form-data">
                        @csrf
                        <div class="form-group"><label for="file">{{ __('Or upload CSV / Excel') }}</label>
                            <input type="file" id="file" name="file" accept=".csv,.txt,.xlsx,.xls" class="form-control-file" required></div>
                        <small class="text-muted d-block mb-2">{{ __('Columns: subject, unit, topic (required); unit_reference, topic_reference, subtopic, estimated_hours (optional).') }}</small>
                        <button class="btn btn-outline-primary"><i class="fas fa-upload"></i> {{ __('Import file') }}</button>
                    </form>
                    @foreach ($imports as $im)
                        <hr class="my-2"><small><strong>{{ $im->filename }}</strong>: {{ __(':ok added, :bad errors', ['ok' => $im->rows_ok, 'bad' => $im->rows_failed]) }}
                            @if ($im->errors)<ul class="mb-0">@foreach (array_slice($im->errors, 0, 8) as $e)<li>{{ $e }}</li>@endforeach
                                @if (count($im->errors) > 8)<li>{{ __('… and :n more', ['n' => count($im->errors) - 8]) }}</li>@endif</ul>@endif</small>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@stop
