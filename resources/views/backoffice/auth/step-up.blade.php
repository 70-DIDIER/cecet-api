@extends('backoffice.layouts.auth')

@section('title', 'Confirmer mon identité')

@section('content')
    <p class="login-subtitle">Cette action est sensible : confirmez votre identité.</p>

    @if ($challenge === null)
        <form action="{{ route('backoffice.step-up.send') }}" method="POST">
            @csrf
            <input type="hidden" name="return" value="{{ $return }}">
            <button type="submit" class="btn-login">
                <span>Recevoir un code par e-mail</span>
                <i class="bi bi-envelope"></i>
            </button>
        </form>
        @error('code')
            <div class="login-error" role="alert">{{ $message }}</div>
        @enderror
    @else
        <p class="small text-center">Un code a été envoyé à <strong>{{ $challenge['sent_to'] }}</strong>.</p>
        <form action="{{ route('backoffice.step-up.verify') }}" method="POST" novalidate>
            @csrf
            <input type="hidden" name="return" value="{{ $return }}">
            <div class="login-form-group">
                <label for="code" class="login-form-label">Code à 6 chiffres</label>
                <div class="login-input-group">
                    <i class="bi bi-key input-icon"></i>
                    <input type="text" id="code" name="code" class="login-input otp-input @error('code') is-invalid @enderror"
                           inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000" required autofocus>
                </div>
                @error('code')
                    <div class="login-error" role="alert">{{ $message }}</div>
                @enderror
            </div>
            <button type="submit" class="btn-login">
                <span>Confirmer</span>
                <i class="bi bi-check2"></i>
            </button>
        </form>
    @endif

    <div class="login-secondary-actions">
        <a href="{{ $return }}" class="forgot-password-link">Annuler</a>
    </div>
@endsection
