<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TallyExportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $companyName,
        public string $periodDescription,
        public int $transactionCount,
        public string $zipFilePath,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Tally XML Export — {$this->companyName} ({$this->periodDescription})",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.tally-export',
            with: [
                'companyName' => $this->companyName,
                'periodDescription' => $this->periodDescription,
                'transactionCount' => $this->transactionCount,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $filename = 'tally-export-'.now()->format('Y-m-d').'.xml.zip';

        return [
            Attachment::fromPath($this->zipFilePath)
                ->as($filename)
                ->withMime('application/zip'),
        ];
    }
}
