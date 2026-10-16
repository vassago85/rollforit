<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>@yield('title', 'Roll4It operator')</title>
  <link rel="stylesheet" href="{{ asset('assets/op.css') }}">
</head>
<body>

<header class="topbar">
  <div class="wrap">
    <div class="brand">
      <span class="brand-mark" aria-hidden="true"></span>
      Roll4It
      <span class="scope">Operator</span>
    </div>
    <nav aria-label="Sections">
      <a href="{{ route('op.dashboard') }}" @class(['active' => request()->routeIs('op.dashboard')])>Dashboard</a>
      <a href="{{ route('op.shops.index') }}" @class(['active' => request()->routeIs('op.shops.*')])>Shops</a>
      <a href="{{ route('op.settings.mail') }}" @class(['active' => request()->routeIs('op.settings.*')])>Settings</a>
    </nav>
    <form method="post" action="{{ route('op.logout') }}">
      @csrf
      <button class="signout" type="submit">Sign out</button>
    </form>
  </div>
</header>

<main>
  <div class="wrap">
    @yield('content')
  </div>
</main>

</body>
</html>
