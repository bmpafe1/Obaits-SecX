<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeamInvite extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $firstName, public string $orgName, public string $email = '') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You have been invited to SecX');
    }

    public function content(): Content
    {
        return new Content(htmlString:
            '<p>Hello ' . e($this->firstName) . ',</p>' .
            '<p>You have been invited to join ' . e($this->orgName) . ' on SecX, the Obaits security platform.</p>' .
            // TELL THEM WHICH ADDRESS TO USE. The first invited user tried his
            // personal address, reasonably, because nothing said otherwise.
            '<p>Sign in with <strong>' . e($this->email) . '</strong> at '
                . '<a href="' . e(rtrim(config('app.url'), '/')) . '/login">'
                . e(rtrim(config('app.url'), '/')) . '</a>.</p>' .
            '<p>You will receive a separate email from SecX with a link to set your password. '
                . 'If it has not arrived, use “Forgot password” at the sign-in page.</p>'
        );
    }
}