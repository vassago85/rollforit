<?php

namespace App\Mail;

use App\Models\Shop;
use App\Models\ShopSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the shop owner the moment a promo day's prize budget hits zero.
 * Fires once per promo date; MerchantNotifier guards re-sends with a cache lock.
 */
class MerchantBudgetDepleted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Shop $shop,
        public readonly ShopSettings $settings,
        public readonly string $promoDate,
        public readonly int $totalPaidOut,
        public readonly int $winners,
        public readonly ?CarbonImmutable $nextOpening = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [$this->shop->email],
            subject: "Roll4It: today's prize budget at {$this->shop->name} is used up",
        );
    }

    public function content(): Content
    {
        $symbol = Money::symbolFor($this->shop->currency);

        return new Content(
            view: 'emails.merchant.budget-depleted',
            with: [
                'shop' => $this->shop,
                'settings' => $this->settings,
                'promoDate' => $this->promoDate,
                'budget' => Money::format((int) $this->settings->daily_budget, $symbol),
                'paidOut' => Money::format($this->totalPaidOut, $symbol),
                'winners' => $this->winners,
                'nextOpening' => $this->nextOpening,
                'nextOpeningLabel' => $this->nextOpening?->format('l j F \a\t H:i'),
            ],
        );
    }
}
