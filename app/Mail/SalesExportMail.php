<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

class SalesExportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly array  $filePaths,
        private readonly string $dateFrom,
        private readonly string $dateTo,
        private readonly array  $countriesGenerated
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[Cosma] Export ventes par pays — %s au %s',
                $this->dateFrom,
                $this->dateTo
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.sales-export-mail',
            with: [
                'dateFrom'           => $this->dateFrom,
                'dateTo'             => $this->dateTo,
                'countriesGenerated' => $this->countriesGenerated,
                'fileCount'          => count($this->filePaths),
            ],
        );
    }

    public function attachments(): array
    {
        return array_map(
            fn(string $path) => Attachment::fromPath($path)->withMime(
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            ),
            $this->filePaths
        );
    }
}