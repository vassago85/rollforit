<!doctype html>
<html>
<body style="font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #0f172a; background: #f8fafc; padding: 24px;">
<div style="max-width: 520px; margin: 0 auto; background: #fff; padding: 28px; border-radius: 12px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);">
    <h1 style="margin: 0 0 8px; font-size: 20px;">Today's Roll4It prize budget is used up</h1>
    <p style="margin: 0 0 20px; color: #475569;">{{ $shop->name }} &middot; promo day {{ $promoDate }}</p>

    <table style="width: 100%; border-collapse: collapse; margin: 0 0 20px;">
        <tr>
            <td style="padding: 10px 0; border-bottom: 1px solid #e2e8f0; color: #475569;">Daily budget</td>
            <td style="padding: 10px 0; border-bottom: 1px solid #e2e8f0; text-align: right; font-weight: 700;">{{ $budget }}</td>
        </tr>
        <tr>
            <td style="padding: 10px 0; border-bottom: 1px solid #e2e8f0; color: #475569;">Paid out today</td>
            <td style="padding: 10px 0; border-bottom: 1px solid #e2e8f0; text-align: right;">{{ $paidOut }}</td>
        </tr>
        <tr>
            <td style="padding: 10px 0; color: #475569;">Winners today</td>
            <td style="padding: 10px 0; text-align: right;">{{ $winners }}</td>
        </tr>
    </table>

    <p style="margin: 0 0 10px; color: #0f172a;">
        The widget has switched over to the "today's prizes have all been won" message. Customers who try to roll today will see a countdown to the next promo day instead of the picker.
    </p>
    @if ($nextOpeningLabel)
        <p style="margin: 0 0 10px; color: #0f172a;">
            <b>Opens again on {{ $nextOpeningLabel }}</b> ({{ $shop->timezone }}).
        </p>
    @endif
    <p style="margin: 0; color: #475569; font-size: 13px;">
        You don't need to do anything. If you want to top up the budget sooner, raise it in Roll4It Settings and rolling resumes immediately on the current promo day.
    </p>
</div>
</body>
</html>
