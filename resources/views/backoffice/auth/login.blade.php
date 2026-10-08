@extends('backoffice.layouts.auth')

@section('title', 'Connexion')

@section('content')
    <p class="login-subtitle">Connectez-vous pour accéder au back-office</p>

    <form action="{{ route('backoffice.login.submit') }}" method="POST" id="loginForm" novalidate>
        @csrf

        <div class="login-form-group">
            <label for="identifier" class="login-form-label">Adresse e-mail</label>
            <div class="login-input-group">
                <i class="bi bi-envelope input-icon"></i>
                <input type="email" id="identifier" name="identifier" class="login-input @error('identifier') is-invalid @enderror"
                       value="{{ old('identifier') }}" placeholder="prenom.nom@cect.example" autocomplete="username" required autofocus>
            </div>
            @error('identifier')
                <div class="login-error" role="alert">{{ $message }}</div>
            @enderror
        </div>

        <div class="login-form-group">
            <label for="password" class="login-form-label">Mot de passe</label>
            <div class="login-input-group">
                <i class="bi bi-shield-lock input-icon"></i>
                <input type="password" id="password" name="password" class="login-input login-input-password @error('password') is-invalid @enderror"
                       placeholder="••••••••" autocomplete="current-password" required>
                <button type="button" class="password-toggle-btn" id="toggle-password" aria-label="Afficher le mot de passe">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
            @error('password')
                <div class="login-error" role="alert">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn-login" id="btn-submit">
            <span>Recevoir mon code de connexion</span>
            <i class="bi bi-arrow-right"></i>
        </button>
    </form>

    <p class="login-hint">Un code à 6 chiffres vous sera envoyé par e-mail pour confirmer votre identité.</p>
@endsection
