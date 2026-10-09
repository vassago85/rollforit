<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Sign in · Roll4It operator</title>
  <link rel="stylesheet" href="{{ asset('assets/op.css') }}">
</head>
<body>
<div class="login-shell">
  <div class="login-card">
    <div class="brand">
      <span class="brand-mark" aria-hidden="true"></span>
      Roll4It
    </div>
    <h1>Operator sign-in</h1>

    @if ($errors->any())
      <div class="err">{{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('op.login') }}" autocomplete="off">
      @csrf

      <label for="email">Email</label>
      <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">

      <label for="password">Password</label>
      <input id="password" type="password" name="password" required autocomplete="current-password">

      <label class="row-check">
        <input type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}>
        Keep me signed in on this device
      </label>

      <button class="btn" type="submit">Sign in</button>
    </form>
  </div>
</div>
</body>
</html>
