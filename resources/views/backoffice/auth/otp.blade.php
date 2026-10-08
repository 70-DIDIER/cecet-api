@extends('backoffice.layouts.auth')

@section('title', 'Code de confirmation')

@section('content')
    <p class="login-subtitle">Saisissez le code envoyé à <strong>{{ $challenge['sent_to'] }}</strong></p>

    <form action="{{ route('backoffice.otp.verify') }}" method="POST" novalidate>
        @csrf

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
            <span>Valider et me connecter</span>
            <i class="bi bi-arrow-right"></i>
        </button>
    </form>

    <div class="login-secondary-actions">
        <form action="{{ route('backoffice.otp.resend') }}" method="POST">
            @csrf
            <button type="submit" class="btn-link-plain">Renvoyer un code</button>
        </form>
        <a href="{{ route('backoffice.login') }}" class="forgot-password-link">Changer de compte</a>
    </div>

    <p class="login-hint">Le code est valable quelques minutes et ne peut servir qu'une fois.</p>
@endsection
