<?php

namespace App\Mail;

use App\Models\User;
use App\Models\WalletRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WithdrawalApproved extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public WalletRequest $request,
        public User $member,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Withdrawal Approved - Mom’s Touch',
        );
    }

    public function content(): Content
    {
        $grossCents = (int) $this->request->amount_cents;
        $feeCents = intdiv($grossCents * 5 + 50, 100);
        $netCents = $grossCents - $feeCents;
        $accountNumber = (string) $this->request->withdrawal_account_number;
        $digits = preg_replace('/\D+/', '', $accountNumber);
        $lastFour = strlen((string) $digits) >= 4 ? substr((string) $digits, -4) : $digits;
        $maskedNumber = $lastFour !== '' ? '•••• '.$lastFour : 'Not provided';

        return new Content(
            view: 'emails.withdrawal-approved',
            with: [
                'memberName' => $this->member->name,
                'memberEmail' => $this->member->email,
                'grossCents' => $grossCents,
                'feeCents' => $feeCents,
                'netCents' => $netCents,
                'accountName' => $this->request->withdrawal_account_holder ?: $this->member->name,
                'accountNumber' => $maskedNumber,
                'accountProvider' => $this->request->withdrawal_account_provider ?: 'Registered account',
                'accountType' => $this->request->withdrawal_account_type === 'e_wallet' ? 'E-Wallet' : 'Bank',
                'submittedAt' => $this->request->created_at,
                'reference' => $this->request->reference,
            ],
        );
    }
}
