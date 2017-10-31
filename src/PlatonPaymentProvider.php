<?php
declare(strict_types=1);

namespace RabbitCMS\Payments\Platon;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareTrait;
use RabbitCMS\Payments\Concerns\PaymentProvider;
use RabbitCMS\Payments\Contracts\ContinuableInterface;
use RabbitCMS\Payments\Contracts\OrderInterface;
use RabbitCMS\Payments\Contracts\PaymentProviderInterface;
use RabbitCMS\Payments\Entities\CardToken;
use RabbitCMS\Payments\Entities\Transaction;
use RabbitCMS\Payments\Support\Action;
use RabbitCMS\Payments\Support\Invoice;

/**
 * Class PlatonPaymentProvider
 *
 * @package RabbitCMS\Payments\Platon
 */
class PlatonPaymentProvider implements PaymentProviderInterface
{
    use LoggerAwareTrait;
    use PaymentProvider;

    const PUBLIC_URL = 'https://secure.platononline.com/payment/auth';

    protected static $statuses = [
        'SALE' => Transaction::STATUS_SUCCESSFUL,
        'REFUND' => Transaction::STATUS_REFUND,
        'CHARGEBACK' => Transaction::STATUS_REFUND,
    ];

    /**
     * @return string
     */
    public function getProviderName(): string
    {
        return 'platon';
    }

    /**
     * @param OrderInterface $order
     *
     * @param callable|null  $callback
     *
     * @return ContinuableInterface
     */
    public function createPayment(OrderInterface $order, callable $callback = null): ContinuableInterface
    {
        $payment = $order->getPayment();
        if ($callback) {
            call_user_func($callback, $payment, $this);
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
                'description' => Str::limit($payment->getDescription(), 255, '')
            ],
            'email' => $client->getEmail(),
            'first_name' => $client->getFirstName(),
            'last_name' => $client->getLastName(),
            'phone' => $client->getPhone(),
        ];

        $transaction = $this->makeTransaction($order, $payment);

        $data['order'] = $transaction->getTransactionId();

        if ($payment->getCardId() === 0) {
            $data['data'][] = 'recurring';
        }

        return (new Action($this, Action::ACTION_OPEN, $this->sign($data)))
            ->setUrl(self::PUBLIC_URL)
            ->setMethod(Action::METHOD_POST);
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function callback(ServerRequestInterface $request): ResponseInterface
    {
        $this->logger->debug('callback', [
            'ip' => $request->getServerParams()['REMOTE_ADDR'],
            'uri' => $request->getUri(),
            'query' => $request->getQueryParams(),
            'cookies' => $request->getCookieParams(),
            'headers' => $request->getHeaders(),
            'body' => $request->getBody()->getContents()
        ]);
        $data = $request->getParsedBody();


        if ($this->sign2($data) !== $data['sign']) {
            throw new RuntimeException('Invalid signature');
        }

        if (array_key_exists($data['status'], self::$statuses)) {
            $invoice = new Invoice(
                $this,
                (string)$data['id'],
                (string)$data['order'],
                Transaction::TYPE_PAYMENT,
                (int)self::$statuses[$data['status']],
                (float)$data['amount']
            );

            if (array_key_exists('rc_token', $data)) {
                $invoice->setCard(new CardToken([
                    'card' => $data['card'],
                    'token' => $data['rc_token'],
                    'data' => [
                        'rc_id' => $data['rc_id']
                    ]
                ]));
            }
            $this->manager->process($invoice);
        }
        return new Response();
    }

    /**
     * @param array $data
     *
     * @return string
     */
    protected function sign2(array $data): string
    {
        return md5(strtoupper(
            strrev($data['email'] ?? '') .
            $this->config('password') .
            $data['order'] .
            strrev(substr($data['card'], 0, 6) . substr($data['card'], -4))
        ));
    }

    /**
     * @param array $data
     *
     * @return array
     */
    public function sign(array $data): array
    {
        $data['data'] = base64_encode(json_encode($data['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $data['sign'] = md5(strtoupper(
            strrev($data['key'] = $this->config('merchant')) .
            strrev($data['payment']) .
            strrev($data['data']) .
            strrev($data['url']) .
            strrev($this->config('password'))
        ));
        return $data;
    }

    /**
     * @return bool
     */
    public function isValid(): bool
    {
        return !empty($this->config('merchant')) && !empty($this->config('password'));
    }
}
