<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use SecureAccess\Models\PendingRegistration;
use SecureAccess\Notifications\ActivateAccount;
use SecureAccess\Tests\TestCase;

class ActivationEmailTest extends TestCase
{
    public function test_rendered_email_links_preserve_the_signed_activation_url(): void
    {
        foreach (['New User', null] as $name) {
            $token = str_repeat('a', 64);
            $registration = PendingRegistration::create([
                'name' => $name,
                'email' => 'new@example.com',
                'token' => PendingRegistration::hashToken($token),
                'expires_at' => now()->addMinutes(5),
            ]);
            $notification = new ActivateAccount($registration, $token);
            $url = $notification->activationUrl();

            Notification::route('mail', $registration->email)->notify($notification);

            $email = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();
            $text = $email->getTextBody();
            $this->assertStringContainsString($url, $text);
            $this->assertStringNotContainsString('&amp;', $text);
            preg_match('~https?://\S+~', $text, $matches);
            $this->assertSame($url, $matches[0]);
            $this->get($matches[0])->assertOk();

            $document = new \DOMDocument;
            @$document->loadHTML($email->getHtmlBody());
            foreach ($document->getElementsByTagName('a') as $link) {
                $this->assertSame($url, $link->getAttribute('href'));
            }
            $this->assertStringContainsString($url, $document->textContent);

            $this->get(str_replace('signature=', 'signature=invalid', $url))
                ->assertRedirect(route('secure-access.register'))
                ->assertSessionHasErrors('activation');
            $registration->delete();
        }
    }

    public function test_html_email_escapes_registration_names(): void
    {
        $registration = new PendingRegistration([
            'name' => '<script>alert("name")</script>',
            'expires_at' => now()->addMinutes(5),
        ]);
        $registration->id = 1;
        $message = (new ActivateAccount($registration, str_repeat('a', 64)))
            ->toMail(new AnonymousNotifiable);
        $html = (string) $message->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
