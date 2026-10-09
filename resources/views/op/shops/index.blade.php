@extends('layouts.op')

@section('title', 'Shops · Roll4It operator')

@section('content')
  <h1>Shops</h1>
  <p class="subtitle">Every shop that has installed Roll4It. Rolls shown are over the last 7 days (live orders only, test rolls excluded).</p>

  <section class="panel">
    <div class="panel-head">
      <h2>{{ $shops->total() }} shop{{ $shops->total() === 1 ? '' : 's' }}</h2>
      <form method="get" class="search">
        <input type="search" name="q" value="{{ $search }}" placeholder="Search by domain, name, or email">
        <button type="submit">Search</button>
      </form>
    </div>

    @if ($shops->isEmpty())
      <div style="padding: 32px 20px; color: var(--muted); text-align: center;">
        @if ($search !== '')
          No shops matched &ldquo;{{ $search }}&rdquo;.
        @else
          No shops have installed Roll4It yet.
        @endif
      </div>
    @else
      <table>
        <thead>
          <tr>
            <th>Shop</th>
            <th>Domain</th>
            <th>Installed</th>
            <th>Promo</th>
            <th>POS</th>
            <th>Terms</th>
            <th class="right">Rolls&nbsp;7d</th>
            <th class="right">Winners</th>
            <th class="right">Payout</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($shops as $shop)
            @php
              $settings = $shop->settings;
              $counts = $rollCounts->get($shop->id);
              $payoutCents = (int) ($counts->payout ?? 0);
              $symbol = \App\Support\Money::symbolFor($shop->currency ?: 'ZAR');
            @endphp
            <tr>
              <td><a href="{{ route('op.shops.show', $shop) }}">{{ $shop->name ?: '—' }}</a></td>
              <td class="mono">{{ $shop->domain }}</td>
              <td>
                @if ($shop->uninstalled_at)
                  <span class="pill danger" title="Uninstalled {{ $shop->uninstalled_at->format('Y-m-d H:i') }}">Uninstalled</span>
                @else
                  {{ $shop->installed_at?->format('Y-m-d') ?: '—' }}
                @endif
              </td>
              <td>
                @if ($settings && $settings->enabled)
                  <span class="pill on">On</span>
                @else
                  <span class="pill off">Off</span>
                @endif
              </td>
              <td>
                @if ($settings && $settings->pos_enabled)
                  <span class="pill on">On</span>
                @else
                  <span class="pill off">—</span>
                @endif
              </td>
              <td>
                @if ($settings && $settings->terms_url && $settings->terms_published_at)
                  <span class="pill on">Published</span>
                @else
                  <span class="pill warn">None</span>
                @endif
              </td>
              <td class="right">{{ number_format((int) ($counts->rolls ?? 0)) }}</td>
              <td class="right">{{ number_format((int) ($counts->winners ?? 0)) }}</td>
              <td class="right">{{ \App\Support\Money::format($payoutCents, $symbol) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>

      @if ($shops->hasPages())
        <div class="pager">
          <span>Showing {{ $shops->firstItem() }}–{{ $shops->lastItem() }} of {{ $shops->total() }}</span>
          <div>
            @if ($shops->onFirstPage())
              <span style="opacity: 0.4;">← Prev</span>
            @else
              <a href="{{ $shops->previousPageUrl() }}">← Prev</a>
            @endif
            <span class="cur">Page {{ $shops->currentPage() }} / {{ $shops->lastPage() }}</span>
            @if ($shops->hasMorePages())
              <a href="{{ $shops->nextPageUrl() }}">Next →</a>
            @else
              <span style="opacity: 0.4;">Next →</span>
            @endif
          </div>
        </div>
      @endif
    @endif
  </section>
@endsection
