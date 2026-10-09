@extends('layouts.op')

@section('title', 'Dashboard · Roll4It operator')

@section('content')
  <h1>Overview</h1>
  <p class="subtitle">What's happening across every shop that has installed Roll4It.</p>

  <section class="cards">
    <article class="card">
      <p class="num">{{ number_format($metrics['installed']) }}</p>
      <p class="label">Installed shops</p>
    </article>
    <article class="card">
      <p class="num amber">+{{ number_format($metrics['new_today']) }}</p>
      <p class="label">Installed today</p>
    </article>
    <article class="card">
      <p class="num">{{ number_format($metrics['rolls_today']) }}</p>
      <p class="label">Rolls today (all shops)</p>
    </article>
    <article class="card">
      <p class="num green">{{ $metrics['payout_today_formatted'] }}</p>
      <p class="label">{{ number_format($metrics['winners_today']) }} winners · payout today</p>
    </article>
  </section>

  <section class="panel">
    <div class="panel-head">
      <h2>Recently installed shops</h2>
      <a href="{{ route('op.shops.index') }}">View all →</a>
    </div>
    @if ($recentShops->isEmpty())
      <div style="padding: 32px 20px; color: var(--muted); text-align: center;">
        No shops have installed Roll4It yet.
      </div>
    @else
      <table>
        <thead>
          <tr>
            <th>Shop</th>
            <th>Domain</th>
            <th>Installed</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($recentShops as $shop)
            <tr>
              <td><a href="{{ route('op.shops.show', $shop) }}">{{ $shop->name ?: '—' }}</a></td>
              <td class="mono">{{ $shop->domain }}</td>
              <td>{{ $shop->installed_at?->format('Y-m-d H:i') ?: '—' }}</td>
              <td>
                @if ($shop->uninstalled_at)
                  <span class="pill danger">Uninstalled</span>
                @else
                  <span class="pill on">Installed</span>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </section>
@endsection
