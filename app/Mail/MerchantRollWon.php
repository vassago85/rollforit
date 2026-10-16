<?php

namespace App\Mail;

use App\Models\Roll;
use App\Models\Shop;
use App\Models\ShopSettings;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the shop owner each time a real customer wins a Roll4It prize.
 * Test and simulator rolls never fire this.
 */
class MerchantRollWon extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Shop $shop,
        public readonly ShopSettings $settings,
        public readonly Roll $roll,
    ) {}

    public function envelope(): Envelope
    {
        $amount = Money::format((int) $this->roll->payout, Money::symbolFor($this->shop->currency));

        return new Envelope(
            to: [$this->shop->email],
            subject: "Roll4It: someone just won {$amount} at {$this->shop->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.merchant.roll-won',
            with: [
                'shop' => $this->shop,
                'roll' => $this->roll,
                'symbol' => Money::symbolFor($this->shop->currency),
                'payout' => Money::format((int) $this->roll->payout, Money::symbolFor($this->shop->currency)),
                'cart' => Money::format((int) $this->roll->cart_subtotal, Money::symbolFor($this->shop->currency)),
            ],
        );
    }
}
