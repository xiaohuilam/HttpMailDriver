<?php

namespace Skyracer2012\HttpMailDriver;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Str;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

class HttpTransport extends AbstractTransport
{
    protected Client $client;
    protected string $key;
    protected string $url;
    protected bool $dkim_enabled;
    protected ?string $dkim_domain;
    protected ?string $dkim_selector;
    protected ?string $dkim_private_key;

    public function __construct(string $url, string $key, bool $dkim_enabled, ?string $dkim_domain, ?string $dkim_selector, ?string $dkim_private_key)
    {
        parent::__construct(new EventDispatcher());

        $this->key = $key;
        $this->url = $url;
        $this->dkim_enabled = $dkim_enabled;
        $this->dkim_domain = $dkim_domain;
        $this->dkim_selector = $dkim_selector;
        $this->dkim_private_key = $dkim_private_key;
        $this->client = new Client();
    }

    protected function getPayload(Email $email): array
    {
        //Mailchannels Format as in https://api.mailchannels.net/tx/v1/documentation
        $personalization = [
            'to' => $this->mapContactsToNameEmail($email->getTo()),
            'cc' => $this->mapContactsToNameEmail($email->getCc()),
            'bcc' => $this->mapContactsToNameEmail($email->getBcc()),
        ];

        if($this->dkim_enabled)
        {
            $personalization['dkim_domain'] = $this->dkim_domain;
            $personalization['dkim_selector'] = $this->dkim_selector;
            $personalization['dkim_private_key'] = $this->dkim_private_key;
        }

        $content = [];

        if($email->getTextBody())
        {
            $content[] = [
                'type' => 'text/plain',
                'value' => $email->getTextBody(),
            ];
        }

        if($email->getHtmlBody())
        {
            $content[] = [
                'type' => 'text/html',
                'value' => $email->getHtmlBody(),
            ];
        }


        return [
            'headers' => [
                'Authorization' => $this->key,
                'Accept'        => 'application/json',
            ],
            'json' => [
                'subject' => $email->getSubject(),
                'personalizations' => [
                    $personalization
                ],
                'from' => [
                    'name' => $email->getFrom()[0]->getName(),
                    'email' => $email->getFrom()[0]->getAddress(),
                ],
                'content' => $content
            ],
        ];
    }

    protected function mapContactsToNameEmail($contacts): array
    {
        $formatted = [];
        if (empty($contacts)) {
            return [];
        }
        foreach ($contacts as $contact) {
            if(!$contact instanceof Address)
                continue;
            $formatted[] =  [
                'name' => $contact->getName(),
                'email' => $contact->getAddress()
            ];
        }
        return $formatted;
    }

    /**
     * @throws GuzzleException
     */
    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $payload = $this->getPayload($email);

        $res = $this->client->request('POST', $this->url, $payload);
        $json = json_decode($res->getBody()->__toString());

        if ($json && $json->status === 'ok' && isset($json->responseStatus) && Str::startsWith($json->responseStatus, '20')) {
            return;
        }

        $errors = isset($json->responseStatus) ? $json->responseStatus : 'Unknown error';
        if (isset($json->response)) {
            $response = json_decode($json->response);
            if (isset($response->errors)) {
                $errors = collect($response->errors)->implode(', ');
            }
        }

        throw new TransportException('Unable to send an email: ' . $errors);
    }

    public function __toString(): string
    {
        return 'http';
    }
}
