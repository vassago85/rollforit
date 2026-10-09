<h2>Roll for It: Terms and Conditions</h2>

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
    The promotion runs on {{ $daysText }} from {{ $settings->start_time }} to {{ $settings->end_time }}
    ({{ $shop->timezone }} time). Each of these days is a "promo day". The promoter's server clock decides
    whether the promotion is open.
</p>

<h3>3. How to enter</h3>
<ul>
    <li>Add products worth at least {{ $minOrder }} to your cart. Gift cards and shipping do not count towards this amount.</li>
    <li>On the cart page, enter your email address, pick a number from 1 to 10 and roll the die.</li>
    <li>The number you pick is for fun only. It does not affect whether you win.</li>
    <li>You may roll once per email address per promo day. If you roll again with the same email address on the same day, you will see your original result.</li>
    <li>The promoter may limit the number of rolls from one connection to prevent abuse.</li>
</ul>

<h3>4. How winners are chosen</h3>
<p>{{ $oddsStatement }}</p>
<p>Every outcome is decided by the promoter's server when you roll. The die animation only shows the result.</p>

<h3>5. The prize</h3>
<ul>
    <li>A win is a discount of up to {{ $maxPayout }} off your order. The discount equals the value of the qualifying items in your cart when you rolled, up to a maximum of {{ $maxPayout }}.</li>
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
    <li>Your email address is used only to enforce one roll per email address per promo day. It is stored as a one-way keyed hash, not as readable text, and is not used for marketing.</li>
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
