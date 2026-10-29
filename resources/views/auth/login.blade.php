@extends('adminlte::auth.auth-page', ['authType' => 'login'])

@section('title', __('Sign in'))

@section('auth_header', __('Sign in to your study tracker'))

@section('auth_body')
    @if (session('status'))
        <div class="alert alert-success py-2" role="alert">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('login.attempt') }}" novalidate>
        @csrf
        <div class="input-group mb-3">
            <input type="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror"
                   placeholder="{{ __('E-mail') }}" autocomplete="username" autofocus required aria-label="{{ __('E-mail') }}">
            <div class="input-group-append"><div class="input-group-text"><span class="fas fa-envelope"></span></div></div>
            @error('email')<span class="invalid-feedback d-block" role="alert"><strong>{{ $message }}</strong></span>@enderror
        </div>
        <div class="input-group mb-3">
            <input type="password" name="password" class="form-control @error('password') is-invalid @enderror"
                   placeholder="{{ __('Password') }}" autocomplete="current-password" required aria-label="{{ __('Password') }}">
            <div class="input-group-append"><div class="input-group-text"><span class="fas fa-lock"></span></div></div>
            @error('password')<span class="invalid-feedback d-block" role="alert"><strong>{{ $message }}</strong></span>@enderror
        </div>
        <div class="row">
            <div class="col-8">
                <div class="icheck-primary">
                    <input type="checkbox" name="remember" id="remember" value="1" checked>
                    <label for="remember">{{ __('Keep me signed in') }}</label>
                </div>
            </div>
            <div class="col-4"><button type="submit" class="btn btn-primary btn-block">{{ __('Sign in') }}</button></div>
        </div>
    </form>
@stop

@section('auth_footer')
    <p class="my-1 small"><a href="{{ route('language.switch', 'en') }}">English</a> · <a href="{{ route('language.switch', 'si') }}">සිංහල</a></p>
    <p class="my-0 small text-muted">{{ __('Forgot the password? It can be reset on the server with') }} <code>php artisan tracker:set-password</code>.</p>
@stop
