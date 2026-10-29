@php
    $badge = ['not_started' => 'secondary', 'in_progress' => 'info', 'theory_completed' => 'primary', 'questions_practised' => 'purple', 'revision_required' => 'warning', 'mastered' => 'success'][$t->status];
@endphp
<tr>
    <td style="padding-left: {{ 0.75 + $depth * 1.5 }}rem">
        {{ $depth ? '↳ ' : '' }}{{ $t->name }}
        @if ($t->reference)<small class="text-muted">({{ $t->reference }})</small>@endif
        @if ($t->is_sample)<span class="badge badge-info">{{ __('SAMPLE') }}</span>@endif
        <div class="small text-muted">
            @if ($t->theory_completed_on){{ __('Theory done :d · ', ['d' => $t->theory_completed_on->translatedFormat('d M Y')]) }}@endif
            @if ($t->theory_minutes){{ __(':t theory time · ', ['t' => App\Support\Duration::format($t->theory_minutes)]) }}@endif
            @if (in_array($t->id, $attempted)){{ __('practised') }} @else <span class="text-warning-emphasis">{{ __('no practice questions yet') }}</span>@endif
        </div>
    </td>
    <td><span class="badge badge-{{ $badge }}">{{ __($statuses[$t->status]) }}</span></td>
    <td>{{ $t->confidence ? str_repeat('★', $t->confidence) . str_repeat('☆', 5 - $t->confidence) . ' (' . $t->confidence . '/5)' : '—' }}</td>
    <td>
        <form method="POST" action="{{ route('syllabus.topics.update', $t) }}" class="form-inline flex-nowrap">
            @csrf @method('PUT')
            <select name="status" class="form-control form-control-sm mr-1" aria-label="{{ __('Status for :n', ['n' => $t->name]) }}">
                @foreach ($statuses as $v => $label)<option value="{{ $v }}" @selected($t->status === $v)>{{ __($label) }}</option>@endforeach
            </select>
            <select name="confidence" class="form-control form-control-sm mr-1" aria-label="{{ __('Confidence') }}">
                <option value="">{{ __('Conf.') }}</option>
                @for ($i = 1; $i <= 5; $i++)<option value="{{ $i }}" @selected($t->confidence === $i)>{{ $i }}</option>@endfor
            </select>
            <button class="btn btn-sm btn-outline-primary" aria-label="{{ __('Save') }}">{{ __('Save') }}</button>
        </form>
    </td>
</tr>
