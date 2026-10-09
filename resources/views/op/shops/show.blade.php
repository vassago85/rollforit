@extends('layouts.op')

@section('title', $shop->domain . ' · Roll4It operator')

@section('content')
  <a class="back" href="{{ route('op.shops.index') }}">← All shops</a>
  <h1>{{ $shop->name ?: $shop->domain }}</h1>
  <p class="subtitle">
    <span class="mono">{{ $shop->domain }}</span>
    @if ($shop->uninstalled_at)
      · <span class="pill danger">Uninstalled {{ $shop->uninstalled_at->format('Y-m-d') }}</span>
    @elseif ($shop->installed_at)
      · Installed {{ $shop->installed_at->format('Y-m-d H:i') }}
    @endif
  </p>

  <section class="cards">
    <article class="card">
      <p class="num">{{ number_format($today['rolls']) }}</p>
      <p class="label">Rolls today</p>
    </article>
    <article class="card">
      <p class="num green">{{ $today['payout_formatted'] ?: ($symbol . '0') }}</p>
      <p class="label">{{ number_format($today['winners']) }} winners · payout today</p>
    </article>
    <article class="card">
      <p class="num">{{ number_format($totals['rolls']) }}</p>
      <p class="label">Rolls all-time</p>
    </article>
    <article class="card">
      <p class="num amber">{{ $totals['payout_formatted'] ?: ($symbol . '0') }}</p>
      <p class="label">{{ number_format($totals['winners']) }} winners · payout all-time</p>
    </article>
  </section>

  <div class="grid-2">
    <section class="panel">
      <div class="panel-head"><h2>Shop</h2></div>
      <dl class="kv">
        <dt>Name</dt><dd>{{ $shop->name ?: '—' }}</dd>
        <dt>Primary email</dt><dd>{{ $shop->email ?: '—' }}</dd>
        <dt>Storefront</dt><dd class="mono">@if ($shop->primary_domain_url)<a href="{{ $shop->primary_domain_url }}" target="_blank" rel="noopener">{{ $shop->primary_domain_url }}</a>@else — @endif</dd>
        <dt>Currency</dt><dd>{{ $shop->currency ?: '—' }}</dd>
        <dt>Timezone</dt><dd>{{ $shop->timezone ?: '—' }}</dd>
        <dt>Scopes</dt><dd class="mono">{{ $shop->scopes ?: '—' }}</dd>
        <dt>Token expires</dt>
        <dd>{{ $shop->access_token_expires_at?->toDayDateTimeString() ?: '—' }}</dd>
      </dl>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Promotion</h2></div>
      <dl class="kv">
        <dt>Enabled</dt>
        <dd>
          @if ($settings->enabled)
            <span class="pill on">On</span>
          @else
            <span class="pill off">Off</span>
          @endif
        </dd>
        <dt>Odds mode</dt><dd>{{ $settings->odds_mode }}@if ($settings->isGuaranteed()) · 1 in {{ $settings->odds_n }}@endif</dd>
        <dt>Min order</dt><dd>{{ $settings->min_order ? \App\Support\Money::format($settings->min_order, $symbol) : '—' }}</dd>
        <dt>Max payout</dt><dd>{{ $settings->max_payout ? \App\Support\Money::format($settings->max_payout, $symbol) : '—' }}</dd>
        <dt>Daily budget</dt><dd>{{ $settings->daily_budget ? \App\Support\Money::format($settings->daily_budget, $symbol) : '—' }}</dd>
        <dt>Winners/day</dt><dd>{{ $settings->max_winners_per_day ?: '—' }}</dd>
        <dt>Promo days</dt><dd>{{ ! empty($settings->promo_days) ? implode(', ', $settings->promo_days) : '—' }}</dd>
        <dt>Window</dt><dd>{{ $settings->start_time && $settings->end_time ? $settings->start_time . ' – ' . $settings->end_time : '—' }}</dd>
      </dl>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>POS integration</h2></div>
      <dl class="kv">
        <dt>Enabled</dt>
        <dd>
          @if ($settings->pos_enabled)
            <span class="pill on">On</span>
          @else
            <span class="pill off">Off</span>
          @endif
        </dd>
        <dt>Secret</dt><dd class="mono">{{ $settings->pos_secret_last_four ? '****' . $settings->pos_secret_last_four : '—' }}</dd>
        <dt>Rotated</dt><dd>{{ $settings->pos_secret_rotated_at?->toDayDateTimeString() ?: '—' }}</dd>
      </dl>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Terms</h2></div>
      <dl class="kv">
        <dt>Mode</dt><dd>{{ $settings->terms_mode }}</dd>
        <dt>Published</dt>
        <dd>
          @if ($settings->hasPublishedTerms())
            <span class="pill on">Published</span>
          @else
            <span class="pill warn">Not published</span>
          @endif
        </dd>
        <dt>URL</dt>
        <dd class="mono">
          @if ($settings->terms_url)
            <a href="{{ $settings->terms_url }}" target="_blank" rel="noopener">{{ $settings->terms_url }}</a>
          @else — @endif
        </dd>
        <dt>Published at</dt><dd>{{ $settings->terms_published_at?->toDayDateTimeString() ?: '—' }}</dd>
      </dl>
    </section>
  </div>

  <section class="panel" style="margin-top: 16px;">
    <div class="panel-head"><h2>Last 14 promo days</h2></div>
    @if ($recentDays->isEmpty())
      <div style="padding: 32px 20px; color: var(--muted); text-align: center;">No rolls recorded yet.</div>
    @else
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th class="right">Rolls</th>
            <th class="right">Winners</th>
            <th class="right">Payout</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($recentDays as $row)
            <tr>
              <td>{{ $row['promo_date'] }}</td>
              <td class="right">{{ number_format($row['rolls']) }}</td>
              <td class="right">{{ number_format($row['winners']) }}</td>
              <td class="right">{{ $row['payout_formatted'] }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </section>
@endsection
