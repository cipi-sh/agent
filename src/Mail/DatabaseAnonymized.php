<?php

namespace Cipi\Agent\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DatabaseAnonymized extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $downloadUrl,
        public string $jobId
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Database Anonymization Complete - Download Ready',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'cipi::emails.database-anonymized',
            with: [
                'downloadUrl' => $this->downloadUrl,
                'jobId' => $this->jobId,
                'expiresIn' => '15 minutes',
            ],
        );
    }
}