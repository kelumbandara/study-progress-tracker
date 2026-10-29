@extends('adminlte::page')

@section('title', __('Change password'))
@section('content_header')
    <h1>{{ __('Change password') }}</h1>
@stop

@section('content')
    @include('partials.flash')
    <div class="card card-primary card-outline" style="max-width: 560px">
        <form method="POST" action="{{ route('account.password.update') }}" novalidate>
            @csrf @method('PUT')
            <div class="card-body">
                <div class="form-group">
                    <label for="current_password">{{ __('Current password') }}</label>
                    <input type="password" id="current_password" name="current_password" class="form-control" autocomplete="current-password" required>
                </div>
                <div class="form-group">
                    <label for="password">{{ __('New password') }}</label>
                    <input type="password" id="password" name="password" class="form-control" autocomplete="new-password" minlength="12" required>
                    <small class="form-text text-muted">{{ __('At least 12 characters, with letters and numbers. Changing it signs out every other device.') }}</small>
                </div>
                <div class="form-group mb-0">
                    <label for="password_confirmation">{{ __('Repeat the new password') }}</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" autocomplete="new-password" required>
                </div>
            </div>
            <div class="card-footer"><button class="btn btn-primary"><i class="fas fa-key"></i> {{ __('Change password') }}</button></div>
        </form>
    </div>
@stop
