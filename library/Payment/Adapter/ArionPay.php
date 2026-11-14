<?php

declare(strict_types=1);
/**
 * ArionPay for FOSSBilling: crypto payments that go straight to the merchant's own wallet.
 *
 * The client presses "Pay" on an invoice and goes to the ArionPay payment page. Only a signed ArionPay webhook
 * (invoice.paid) pays the FOSSBilling invoice: the amount is added to the client's balance and the invoice is paid
 * from it, as FOSSBilling's own gateways do. Whether an invoice is paid is decided by ArionPay alone; this adapter
 * never compares crypto amounts.
 *
 * Install: copy ArionPay.php, arionpay.png and the ArionPay/ folder into library/Payment/Adapter/.
 *
 * @license MIT
 */

require_once __DIR__ . '/ArionPay/arionpay-php/autoload.php';

class Payment_Adapter_ArionPay extends Payment_AdapterAbstract implements FOSSBilling\InjectionAwareInterface
{
    public const VERSION = '1.0.0';

    /** Fiat currencies ArionPay can price an invoice in. */
    public const CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'TRY', 'AED', 'INR', 'BRL', 'RUB', 'CNY', 'SGD', 'HKD', 'KRW', 'MXN', 'PLN', 'SEK', 'NOK', 'ZAR'];

    protected ?Pimple\Container $di = null;

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public function __construct(private $config)
    {
        foreach (['api_key' => 'API key', 'api_secret' => 'API secret', 'webhook_secret' => 'Webhook signing secret'] as $key => $label) {
            if (empty($this->config[$key])) {
                throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'ArionPay', ':missing' => $label], 4001);
            }
        }
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Accept Bitcoin, USDT, Litecoin, Ethereum, TRON and more. The money goes straight to your own wallet: ArionPay never holds it. '
                . 'In your ArionPay dashboard, open your store and copy the API key (pk_...) and API secret (sk_...); on the Webhooks page, copy the signing secret (whsec_...). '
                . 'Nothing to paste into ArionPay: every invoice tells ArionPay where to send its payment updates. "Test mode" here creates ArionPay test invoices (no real money).',
            'logo' => [
                'logo' => 'arionpay.png',
                'height' => '30px',
                'width' => '30px',
            ],
            'form' => [
                'api_key' => [
                    'text', [
                        'label' => 'API key (pk_...):',
                        'required_when' => ['enabled' => true],
                    ],
                ],
                'api_secret' => [
                    'password', [
                        'label' => 'API secret (sk_...):',
                        'required_when' => ['enabled' => true],
                        'secret' => true,
                    ],
                ],
                'webhook_secret' => [
                    'password', [
                        'label' => 'Webhook signing secret (whsec_...):',
                        'required_when' => ['enabled' => true],
                        'secret' => true,
                    ],
                ],
                'base_url' => [
                    'text', [
                        'label' => 'ArionPay address (leave empty for https://merchant.arionpay.com):',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * The invoice page: a button to the ArionPay payment page (or an automatic redirect).
     */
    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $invoice = $this->di['db']->getExistingModelById('Invoice', (int) $invoice_id, 'Invoice not found');
        if ($invoice->status === Model_Invoice::STATUS_PAID) {
            return '<p>' . $this->esc('This invoice is already paid.') . '</p>';
        }
        $currency = strtoupper((string) $invoice->currency);
        if (!in_array($currency, self::CURRENCIES, true)) {
            return '<p>' . $this->esc(sprintf('ArionPay cannot take payments in %s.', $currency)) . '</p>';
        }

        try {
            $url = $this->invoiceUrl($invoice);
        } catch (Throwable $e) {
            $this->di['logger']->warning('ArionPay: could not create an invoice for FOSSBilling invoice #%s: %s', $invoice->id, $e->getMessage());

            return '<p>' . $this->esc('The crypto payment could not be started right now. Please try again in a minute.') . '</p>';
        }

        $html = '<div class="arionpay-pay">';
        if ($this->config['test_mode']) {
            $html .= '<p><strong>' . $this->esc('Test mode: no real money is taken.') . '</strong></p>';
        }
        $html .= '<p>' . $this->esc('You will go to the secure ArionPay page to choose a coin and pay. Your invoice is marked paid as soon as the payment is confirmed.') . '</p>';
        $html .= '<a class="btn btn-primary" id="arionpay-pay" href="' . $this->esc($url) . '">' . $this->esc('Pay with crypto') . '</a>';
        $html .= '</div>';
        if ($this->config['auto_redirect']) {
            $html .= '<script type="text/javascript">window.location.href = ' . json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
        }

        return $html;
    }

    /**
     * Webhook from ArionPay (ipn.php?gateway_id=N). Throws when it must be retried or refused, so FOSSBilling answers
     * with an error status and ArionPay sends it again later (for about 3 days).
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        $tx = $this->di['db']->getExistingModelById('Transaction', (int) $id);
        $raw = (string) ($data['http_raw_post_data'] ?? '');
        $header = (string) ($data['server']['HTTP_ARIONPAY_SIGNATURE'] ?? '');

        try {
            $event = ArionPay\Webhook::verify($raw, $header, (string) $this->config['webhook_secret']);
        } catch (ArionPay\SignatureException $e) {
            throw new Payment_Exception('ArionPay webhook refused: :reason', [':reason' => $e->getMessage()], 401);
        }

        // "Send test webhook" events describe a made-up invoice: verified, then forgotten.
        if (ArionPay\Webhook::isTestEvent($event) || !in_array($event['type'], ArionPay\Webhook::EVENT_TYPES, true)) {
            $this->di['db']->trash($tx);

            return;
        }

        $remote = $event['data']['invoice'];
        $metadata = isset($remote['metadata']) && is_array($remote['metadata']) ? $remote['metadata'] : [];
        $invoiceId = (int) ($metadata['fossbilling_invoice_id'] ?? 0);
        if ($invoiceId <= 0 || (int) ($metadata['fossbilling_gateway_id'] ?? 0) !== (int) $gateway_id) {
            // an invoice from the ArionPay dashboard, a payment link, or another FOSSBilling gateway on the same store
            $this->di['db']->trash($tx);

            return;
        }
        $invoice = $this->di['db']->findOne('Invoice', 'id = :id', [':id' => $invoiceId]);
        if (!$invoice instanceof Model_Invoice || !hash_equals((string) $invoice->hash, (string) ($metadata['fossbilling_invoice_hash'] ?? ''))
            || (bool) ($remote['test'] ?? false) !== (bool) $this->config['test_mode']) {
            throw new Payment_Exception('ArionPay webhook does not match a FOSSBilling invoice of this gateway (wrong invoice, or test/live mode differs)', [], 409);
        }

        $this->withLock('fb:arionpay:' . substr(hash('sha256', $gateway_id . ':' . $remote['id']), 0, 50), function () use ($tx, $event, $remote, $invoice, $gateway_id): void {
            // each ArionPay event is handled once, however often it is delivered
            $done = $this->di['db']->findOne('Transaction', 'txn_id = :txn AND gateway_id = :gw AND id != :id AND status = :st', [
                ':txn' => $event['id'], ':gw' => $gateway_id, ':id' => $tx->id, ':st' => Model_Transaction::STATUS_PROCESSED,
            ]);
            if ($done instanceof Model_Transaction) {
                $this->di['db']->trash($tx);

                return;
            }

            $tx->invoice_id = $invoice->id;
            $tx->txn_id = $event['id'];
            $tx->txn_status = $event['type'];
            $tx->type = Payment_Transaction::TXTYPE_PAYMENT;
            $tx->currency = (string) ($remote['currency'] ?? '');
            $tx->amount = (string) ($remote['amountFiat'] ?? '');

            if ($event['type'] === 'invoice.paid') {
                $this->onPaid($tx, $invoice, $remote);
            } else {
                $tx->note = $this->note($event['type'], $remote);
                $tx->status = Model_Transaction::STATUS_PROCESSED;
                $tx->amount = '0';
            }
            $tx->updated_at = date('Y-m-d H:i:s');
            $this->di['db']->store($tx);
        });
    }

    private function onPaid(Model_Transaction $tx, Model_Invoice $invoice, array $remote): void
    {
        $invoiceService = $this->di['mod_service']('Invoice');
        $fresh = $this->di['db']->getExistingModelById('Invoice', $invoice->id);
        if ($fresh->status === Model_Invoice::STATUS_PAID) {
            // keep the money visible to the admin, but do not pay or credit twice
            $tx->status = Model_Transaction::STATUS_PROCESSED;
            $tx->note = $this->prefix($remote) . sprintf('ArionPay invoice %s is paid, but FOSSBilling invoice #%s was already paid. If it was paid another way, this may be a double payment: check your wallet and refund the client if needed.', $remote['id'], $invoice->id);
            $tx->amount = '0';

            return;
        }

        // The ArionPay invoice must still match: same currency, same total to the cent (not a payment tolerance: it
        // catches an invoice that was edited after the client was sent to pay).
        $expected = $this->amount($fresh);
        $paid = is_numeric($remote['amountFiat'] ?? null) ? number_format((float) $remote['amountFiat'], 2, '.', '') : '';
        if (strtoupper((string) ($remote['currency'] ?? '')) !== strtoupper((string) $fresh->currency) || $paid !== $expected) {
            $tx->status = Model_Transaction::STATUS_ERROR;
            $tx->error = sprintf('ArionPay invoice %s is paid (%s %s), but that does not match FOSSBilling invoice #%s (%s %s), so it was NOT marked paid. Add the payment by hand after checking.', $remote['id'], $paid !== '' ? $paid : '?', $remote['currency'] ?? '', $fresh->id, $expected, $fresh->currency);
            $tx->error_code = 409;

            return;
        }

        $client = $this->di['db']->getExistingModelById('Client', $fresh->client_id);
        $clientService = $this->di['mod_service']('client');
        $txid = '';
        if (!empty($remote['transactions']) && is_array($remote['transactions'])) {
            $last = end($remote['transactions']);
            $txid = is_array($last) && isset($last['txid']) && is_scalar($last['txid']) ? (string) $last['txid'] : '';
        }
        $clientService->addFunds($client, (float) $paid, 'ArionPay invoice ' . $remote['id'] . ($txid !== '' ? ' (transaction ' . $txid . ')' : ''), [
            'type' => 'transaction',
            'rel_id' => $tx->id,
        ]);
        if (!$invoiceService->isInvoiceTypeDeposit($fresh)) {
            if (!$fresh->approved) {
                $invoiceService->approveInvoice($fresh, ['use_credits' => false]);
            }
            $invoiceService->payInvoiceWithCredits($fresh);
        } else {
            $invoiceService->markAsPaid($fresh);
        }

        $tx->status = Model_Transaction::STATUS_PROCESSED;
        $tx->note = $this->paidNote($remote, $txid);
    }

    /**
     * The ArionPay invoice for this FOSSBilling invoice: the open one when it still fits, otherwise a new one.
     * Idempotency keys make this stateless: the same key returns the same ArionPay invoice.
     */
    private function invoiceUrl(Model_Invoice $invoice): string
    {
        $amount = $this->amount($invoice);
        $currency = strtoupper((string) $invoice->currency);
        $test = (bool) $this->config['test_mode'];
        $site = substr(hash('sha256', (defined('SYSTEM_URL') ? SYSTEM_URL : '') . '|' . $this->config['gateway_id']), 0, 12);
        $base = 'fossbilling-' . $site . '-' . $invoice->id . '-' . $amount . $currency . ($test ? '-test' : '');
        $client = $this->client();

        for ($attempt = 1; $attempt <= 20; ++$attempt) {
            $created = $client->createInvoice([
                'amount' => $amount,
                'currency' => $currency,
                'orderId' => (string) $invoice->id,
                'description' => mb_substr('Invoice #' . $invoice->id . ' at ' . ($invoice->seller_company ?: 'FOSSBilling'), 0, 500),
                'successUrl' => (string) $this->config['thankyou_url'],
                'cancelUrl' => (string) $this->config['invoice_url'],
                'callbackUrl' => SYSTEM_URL . 'ipn.php?gateway_id=' . (int) $this->config['gateway_id'],
                'metadata' => [
                    'fossbilling_invoice_id' => (int) $invoice->id,
                    'fossbilling_invoice_hash' => (string) $invoice->hash,
                    'fossbilling_gateway_id' => (int) $this->config['gateway_id'],
                    'platform' => 'fossbilling',
                ],
                'idempotencyKey' => $base . '-' . $attempt,
                'test' => $test,
            ]);
            $expires = isset($created['expiresAt']) ? strtotime((string) $created['expiresAt']) : 0;
            $open = ($created['status'] ?? 'new') === 'new' && $expires > time() + 300;
            $inProgress = in_array($created['status'] ?? '', ['pending', 'paid_partial', 'claim_pending'], true);
            if ($open || $inProgress) {
                return (string) $created['invoiceUrl'];
            }
            // expired (or about to): the next attempt number makes a fresh invoice
        }

        throw new Payment_Exception('Too many ArionPay invoices for this invoice');
    }

    private function amount(Model_Invoice $invoice): string
    {
        return number_format($this->di['mod_service']('Invoice')->getTotalWithTax($invoice), 2, '.', '');
    }

    private function client(): ArionPay\Client
    {
        return new ArionPay\Client((string) $this->config['api_key'], (string) $this->config['api_secret'], [
            'baseUrl' => !empty($this->config['base_url']) ? (string) $this->config['base_url'] : 'https://merchant.arionpay.com',
            'appInfo' => 'fossbilling arionpay-fossbilling/' . self::VERSION,
        ]);
    }

    private function withLock(string $name, callable $callback): void
    {
        if ((int) $this->di['dbal']->fetchOne('SELECT GET_LOCK(:n, 10)', ['n' => $name]) !== 1) {
            throw new Payment_Exception('Busy with another delivery of this payment; ArionPay will send it again', [], 503);
        }
        try {
            $callback();
        } finally {
            $this->di['dbal']->fetchOne('SELECT RELEASE_LOCK(:n)', ['n' => $name]);
        }
    }

    private function note(string $type, array $remote): string
    {
        $s = fn (string $k): string => isset($remote[$k]) && is_scalar($remote[$k]) ? mb_substr(strip_tags((string) $remote[$k]), 0, 300) : '';
        $text = match ($type) {
            'invoice.pending' => sprintf('ArionPay invoice %s: %s %s was seen on the network and is waiting for confirmations.', $s('id'), $s('paidCrypto'), $s('asset')),
            'invoice.partial' => sprintf('ArionPay invoice %s: partial payment of %s of %s %s. The invoice stays open for the rest.', $s('id'), $s('paidCrypto'), $s('amountCrypto'), $s('asset')),
            'invoice.expired' => sprintf('ArionPay invoice %s expired without full payment. A late payment can still arrive; if ArionPay accepts it, the invoice is paid then.', $s('id')),
            'invoice.needs_review' => sprintf('ArionPay invoice %s needs your review in the ArionPay dashboard: %s', $s('id'), $s('reviewReason') ?: '-'),
            default => $type,
        };

        return $this->prefix($remote) . $text;
    }

    private function paidNote(array $remote, string $txid): string
    {
        $s = fn (string $k): string => isset($remote[$k]) && is_scalar($remote[$k]) ? mb_substr(strip_tags((string) $remote[$k]), 0, 300) : '';
        $parts = [sprintf('ArionPay invoice %s is paid: %s %s received on %s. Transaction: %s.', $s('id'), $s('paidCrypto'), $s('asset'), $s('network'), $txid !== '' ? $txid : '-')];
        if (!empty($remote['late'])) {
            $parts[] = 'Paid late: the payment arrived after the invoice expired.';
        }
        if (!empty($remote['manual'])) {
            $parts[] = 'Accepted by hand in the ArionPay dashboard.';
        }
        if (!empty($remote['overpaid'])) {
            $parts[] = 'The client paid more than the invoice amount.';
        }
        if (!empty($remote['underpaidWithinTolerance'])) {
            $parts[] = 'The client paid slightly less, and ArionPay accepted it under your store\'s payment tolerance.';
        }
        if (!empty($remote['needsReview'])) {
            $parts[] = 'ArionPay flagged this invoice for review: see your ArionPay dashboard.';
        }

        return $this->prefix($remote) . implode(' ', $parts);
    }

    private function prefix(array $remote): string
    {
        return !empty($remote['test']) ? '[TEST - no real money] ' : '';
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
