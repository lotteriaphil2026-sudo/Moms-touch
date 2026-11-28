<?php

namespace App\Mail;

use App\Models\MemberCertificate;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CertificateIssued extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public MemberCertificate $certificate,
        public User $member,
        public string $certificateUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Certificate of Investment - '.$this->certificate->certificate_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.certificate-issued',
            with: [
                'holderName' => $this->certificate->holder_name,
                'certificateNumber' => $this->certificate->certificate_number,
                'planName' => $this->certificate->plan_name,
                'indicatedDate' => $this->certificate->indicated_date->format('F j, Y'),
                'senderName' => $this->certificate->sender_name,
                'reference' => $this->certificate->reference,
                'certificateUrl' => $this->certificateUrl,
                'isImage' => str_starts_with($this->certificate->mime_type, 'image/'),
            ],
        );
    }

    public function attachments(): array
    {
        return [
            \Illuminate\Mail\Mailables\Attachment::fromStorageDisk(
                'local',
                $this->certificate->attachment_path,
            )->as($this->certificate->original_name)->withMime($this->certificate->mime_type),
        ];
    }
}
