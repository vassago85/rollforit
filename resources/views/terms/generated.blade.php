<h2>Roll4It: Terms and Conditions</h2>

<h3>1. The promoter</h3>
<p>
    This promotion is run by <strong>{{ $settings->promoter_name }}</strong>@if($settings->promoter_registration_number) (registration number {{ $settings->promoter_registration_number }})@endif,
    trading as {{ $shop->name ?? $shop->domain }} ("the promoter").
</p>
<p>
    Physical address: {{ $settings->promoter_address }}<br>
    Email: {{ $settings->promoter_email }}@if($settings->promoter_phone)<br>Phone: {{ $settings->promoter_phone }}@endif
</p>

<h3>2. When it runs</h3>
<p>
    @if ($scheduleMode === 'dates')
        The promotion runs on {{ $promoDatesText }} from {{ $settings->start_time }} to {{ $settings->end_time }}
        ({{ $shop->timezone }} time). Each of these dates is a "promo day".
    @else
        The promotion runs on {{ $daysText }} from {{ $settings->start_time }} to {{ $settings->end_time }}
        ({{ $shop->timezone }} time). Each of these days is a "promo day".
    @endif
    The promoter's server clock decides whether the promotion is open.
</p>

<h3>3. How to enter</h3>
<ul>
    <li>Add products worth at least {{ $minOrder }} to your cart. Gift cards and shipping do not count towards this amount.</li>
    <li>On the cart page, pick {{ $pickPhrase }} and roll {{ $dicePhrase }}. If you are signed in to the store, we use your account. If you are a guest, you will be asked for an email address.</li>
    <li>The number you pick is for fun only. It does not affect whether you win.</li>
    <li>You may roll once per promo day. The limit is enforced per store account, per email address and per phone number. If any of those has already rolled today, the earlier result applies.</li>
    <li>The promoter may limit the number of rolls from one connection to prevent abuse.</li>
</ul>

<h3>3a. Entering in store</h3>
<ul>
    <li>If you are shopping in store, a staff member may offer you a roll before you pay once your sale reaches {{ $minOrder }}.</li>
    <li>You will be asked for your email address or your cell phone number. We use this only to make sure the same person does not roll twice on the same day (online and in-store entries share the same daily limit).</li>
    <li>The in-store {{ $diceCount === 2 ? 'dice are' : 'die is' }} run by the promoter's server, exactly like the online version. The result is final the moment the roll is made.</li>
    <li>If you win, the discount is applied to the sale on the till right away. There is no code to carry away — the win cannot be used on a future visit, cannot be split across sales and cannot be refunded for cash.</li>
    <li>Prizes won in store still come out of the same daily allocation as online wins.</li>
</ul>

<h3>4. How winners are chosen</h3>
<p>{{ $oddsStatement }}</p>
<p>Every outcome is decided by the promoter's server when you roll. The die animation only shows the result.</p>

<h3>5. The prize</h3>
<ul>
    @if($maxPayoutPercent)
        <li>A win is a discount of up to {{ $maxPayoutPercent }}% of the qualifying items in your cart, capped at {{ $maxPayout }}. The discount is the lesser of these two caps and is never more than the value of the qualifying items when you rolled.</li>
    @else
        <li>A win is a discount of up to {{ $maxPayout }} off your order. The discount equals the value of the qualifying items in your cart when you rolled, up to a maximum of {{ $maxPayout }}.</li>
    @endif
    <li>The prize is a single-use discount code. It is applied to your cart automatically and is valid until 23:59 ({{ $shop->timezone }} time) on the promo day you won.</li>
    <li>The code can be used once, cannot be combined with other discounts and has no cash value.</li>
    <li>The discount never covers shipping and never applies to gift cards.</li>
    @if(count($excludedTitles))
        <li>Products in these collections are excluded and do not count towards the prize value: {{ implode(', ', $excludedTitles) }}.</li>
    @endif
    <li>To use the code, your order must still meet the minimum value shown on the code at checkout.</li>
</ul>

<h3>6. Daily allocation</h3>
<p>
    The promoter sets a daily prize allocation.
    @if($dailyBudget) The total value of prizes issued on one promo day will not exceed {{ $dailyBudget }}. @endif
    @if($settings->max_winners_per_day) No more than {{ $settings->max_winners_per_day }} prizes will be issued on one promo day. @endif
    The promotion can close early on a promo day once the daily allocation is used. If a win would go over the remaining
    daily allocation, the prize is reduced to the amount that remains.
</p>

<h3>7. How wins are recorded</h3>
<p>
    Every roll is recorded on the promoter's server with the date and time, the outcome, the prize value, the
    discount code issued and the version of these terms that applied. These records are the final word on whether
    a roll won.
</p>

<h3>8. Your information</h3>
<ul>
    <li>If you are signed in, we store a one-way hash of your store account id. If you are a guest (or rolling in store), we store a one-way hash of the email or phone number you give us. These are used only to enforce one roll per promo day and are never used for marketing.</li>
    <li>Your IP address is recorded to prevent abuse and is deleted after {{ $ipDays }} days.</li>
    <li>Roll records are kept for {{ $retentionYears }} years to meet record-keeping obligations for promotional competitions, then deleted.</li>
    <li>To ask about or delete your information, contact {{ $settings->promoter_email }}.</li>
</ul>

<h3>9. General</h3>
<ul>
    <li>The promoter may change or end the promotion by updating this page. Changes do not affect prizes already won.</li>
    <li>The promoter may disqualify entries that abuse the promotion, for example by using many email addresses.</li>
    <li>This promotion is subject to the laws of South Africa, including the Consumer Protection Act.</li>
</ul>

<p class="rfi-terms-version"><small>Version {{ $nextVersion }}, published {{ $date }}.</small></p>
