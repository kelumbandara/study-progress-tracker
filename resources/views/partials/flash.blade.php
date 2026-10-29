@if (session('status'))
    <div class="alert alert-success" role="alert"><i class="fas fa-check-circle"></i> {{ session('status') }}</div>
@endif
@foreach ((array) session('warnings', []) as $w)
    <div class="alert alert-warning" role="alert"><i class="fas fa-exclamation-triangle"></i> {{ $w }}</div>
@endforeach
@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <strong>{{ __('Please fix the following:') }}</strong>
        <ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif
