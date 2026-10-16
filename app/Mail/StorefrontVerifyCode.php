<?php

namespace App\Mail;

use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The one-time 6-digit code the storefront widget sends when a guest shopper
 * types their email into the Roll4It cart drawer. Delivered via Mailgun; the
 * email itself is deliberately short so it reads fine in a notification popup.
 */
class StorefrontVerifyCode extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Shop $shop,
        public readonly string $email,
        public readonly string $code,
        public readonly CarbonImmutable $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        // The shopper only recognises the shop name, not "Roll4It", so the inbox
        // row should read as if it came straight from the merchant. We can't put
        // the shop's own mailbox in the SMTP From — it would fail DKIM/SPF on
        // our Mailgun domain and land in spam — so we keep our authenticated
        // address as the From address but set the shop's name as the display
        // name. Replies route to the shop's actual mailbox via Reply-To.
        $from = new Address(
            (string) config('mail.from.address'),
            $this->shop->name ?: (string) config('mail.from.name'),
        );

        $replyTo = $this->shop->email
            ? [new Address($this->shop->email, $this->shop->name)]
            : [];

        return new Envelope(
            from: $from,
            to: [$this->email],
            replyTo: $replyTo,
            subject: "{$this->shop->name}: your confirmation code {$this->code}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.storefront.verify-code',
            with: [
                'shopName' => $this->shop->name,
                'code' => $this->code,
                'expiresAt' => $this->expiresAt,
                'ttlMinutes' => (int) now()->diffInMinutes($this->expiresAt, false),
            ],
        );
    }
}
