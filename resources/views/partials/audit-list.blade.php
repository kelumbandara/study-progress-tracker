{{-- $audit: AuditLog collection for one record --}}
<div class="card">
    <div class="card-header"><h3 class="card-title">{{ __('Change history') }}</h3></div>
    @if ($audit->isEmpty())
        <div class="card-body text-muted small">{{ __('This record has not been changed since it was entered.') }}</div>
    @else
        <ul class="list-group list-group-flush small">
            @foreach ($audit as $a)
                <li class="list-group-item">
                    <strong>{{ __(ucfirst($a->action)) }}</strong> · {{ $a->created_at->timezone(config('app.timezone'))->translatedFormat('d M Y H:i') }}
                    @foreach (($a->new_values ?? []) as $field => $new)
                        <div>{{ str_replace('_', ' ', $field) }}: <span class="text-muted">{{ is_null($a->old_values[$field] ?? null) ? '—' : $a->old_values[$field] }}</span> → <strong>{{ is_null($new) ? '—' : $new }}</strong></div>
                    @endforeach
                </li>
            @endforeach
        </ul>
    @endif
</div>
