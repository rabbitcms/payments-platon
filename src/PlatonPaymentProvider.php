<?php

declare(strict_types=1);

namespace RabbitCMS\Payments\Platon;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Log\LoggerAwareTrait;
use RabbitCMS\Payments\Concerns\PaymentProvider;
use RabbitCMS\Payments\Contracts\{ContinuableInterface, OrderInterface, PaymentProviderInterface};
use RabbitCMS\Payments\Entities\{CardToken, Transaction};
use RabbitCMS\Payments\Support\{Action, Invoice};
use Nyholm\Psr7\Response;

/**
 * Class PlatonPaymentProvider
 */
class PlatonPaymentProvider implements PaymentProviderInterface
{
    use LoggerAwareTrait;
    use PaymentProvider;

    protected const PUBLIC_URL = 'https://secure.platononline.com/payment/auth';

    protected static $statuses = [
        'SALE' => Transaction::STATUS_SUCCESSFUL,
        'REFUND' => Transaction::STATUS_REFUND,
        'CHARGEBACK' => Transaction::STATUS_REFUND,
    ];

    public function getProviderName(): string
    {
        return 'platon';
    }

    public function createPayment(
        OrderInterface $order,
        callable $callback = null,
        array $options = []
    ): ContinuableInterface {
        $payment = $order->getPayment();
        if ($callback) {
            $callback($payment, $this);
        }
        $client = $payment->getClient();
        $amount = round($payment->getAmount(), 2);
        $data = [
            'payment' => 'CC',
            'url' => $payment->getReturnUrl(),
            'lang' => $payment->getLanguage(),
            'data' => [
                'amount' => number_format($amount, 2, '.', ''),
                'currency' => $payment->getCurrency(),
                'description' => Str::limit($payment->getDescription(), 255, ''),
            ],
            'email' => $client->getEmail(),
            'first_name' => $client->getFirstName(),
            'last_name' => $client->getLastName(),
            'phone' => $client->getPhone(),
        ];

        $transaction = $this->makeTransaction($order, $payment, $options);

        $data['order'] = $transaction->getTransactionId();

        if ($payment->getCardId() === 0) {
            $data['data'][] = 'recurring';
        }

        return (new Action($this, Action::ACTION_OPEN, $this->sign($data)))
            ->setUrl(self::PUBLIC_URL)
            ->setMethod(Action::METHOD_POST);
    }

    public function callback(ServerRequestInterface $request): ResponseInterface
    {
        $this->logger->debug('callback', [
            'ip' => $request->getServerParams()['REMOTE_ADDR'],
            'uri' => $request->getUri(),
            'query' => $request->getQueryParams(),
            'cookies' => $request->getCookieParams(),
            'headers' => $request->getHeaders(),
            'body' => $request->getBody()->getContents(),
        ]);
        $data = $request->getParsedBody();

        try {
            Validator::validate($data, [
                'sign' => ['required'],
                'status' => ['required'],
                'id' => ['required'],
                'order' => ['required'],
                'amount' => ['required'],
            ]);
        } catch (ValidationException $exception) {
            return new Response($exception->status, [
                'Content-Type' => 'application/json',
            ], json_encode([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ]));
        }

        if ($this->sign2($data) !== $data['sign']) {
            return new Response(403, [
                'Content-Type' => 'application/json',
            ], json_encode(['message' => 'Invalid signature']));
        }

        if (array_key_exists($data['status'], self::$statuses)) {
            $invoice = new Invoice(
                $this,
                (string) $data['id'],
                (string) $data['order'],
                Transaction::TYPE_PAYMENT,
                (int) self::$statuses[$data['status']],
                (float) $data['amount']
            );

            if (array_key_exists('rc_token', $data)) {
                $invoice->setCard(new CardToken([
                    'card' => $data['card'],
                    'token' => $data['rc_token'],
                    'data' => [
                        'rc_id' => $data['rc_id'],
                    ],
                ]));
            }
            $this->manager->process($invoice);
        }

        return new Response();
    }

    protected function sign2(array $data): string
    {
        return md5(strtoupper(
            strrev($data['email'] ?? '').
            $this->config('password').
            $data['order'].
            strrev(substr($data['card'], 0, 6).substr($data['card'], -4))
        ));
    }

    public function sign(array $data): array
    {
        $data['data'] = base64_encode(json_encode($data['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $data['sign'] = md5(strtoupper(
            strrev($data['key'] = $this->config('merchant')).
            strrev($data['payment']).
            strrev($data['data']).
            strrev($data['url']).
            strrev($this->config('password'))
        ));

        return $data;
    }

    public function isValid(): bool
    {
        return ! empty($this->config('merchant')) && ! empty($this->config('password'));
    }
}
