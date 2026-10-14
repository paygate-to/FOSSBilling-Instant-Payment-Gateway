<?php

declare(strict_types=1);
/**
 * PayGate.to card payments for FOSSBilling.
 *
 * Visa, Mastercard, Apple Pay, Google Pay and more through PayGate.to's hosted checkout, paid out
 * instantly in USDC (Polygon) to your own wallet. No KYC, no merchant account.
 *
 * Install: copy this PayGateCard folder to library/Payment/Adapter/ of your FOSSBilling installation,
 * then add it in System > Payment gateways > New payment gateway.
 *
 * @see https://paygate.to/instant-payment-gateway/fossbilling/
 * @see https://paygate.to/docs/payment-gateway-api/
 */

use Pimple\Container;

class Payment_Adapter_PayGateCard extends Payment_AdapterAbstract implements FOSSBilling\InjectionAwareInterface
{
    public const MODULE_VERSION = '1.0.0';
    private const TX_TYPE = 'PayGate.to card';

    /** Native coins a card payout can arrive in => their PayGate.to coin path (priced via info.php). */
    private const NATIVE_PAYOUTS = ['polygon_pol' => 'polygon/pol', 'eth' => 'eth', 'bep20_bnb' => 'bep20/bnb'];

    protected ?Container $di = null;

    public function setDi(Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Container
    {
        return $this->di;
    }

    public function __construct(private $config)
    {
        if (trim((string) ($this->config['wallet'] ?? '')) === '') {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'PayGate.to card', ':missing' => 'USDC (Polygon) wallet'], 4001);
        }
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Accept Visa, Mastercard, Apple Pay, Google Pay and more with instant payouts in USDC (Polygon) to your own wallet. No KYC, no merchant account. Setup guide: https://paygate.to/instant-payment-gateway/fossbilling/',
            'logo' => [
                'logo' => 'PayGateCard/logo.png',
                'height' => '50px',
                'width' => '50px',
            ],
            'form' => [
                'wallet' => [
                    'text', [
                        'label' => 'USDC (Polygon) wallet for payouts',
                        'description' => 'A self-custodial wallet you control, starting with 0x. Every payment is forwarded to it instantly.',
                        'required' => true,
                    ],
                ],
                'provider' => [
                    'text', [
                        'label' => 'Provider (optional)',
                        'description' => 'Leave empty to show the hosted page, which lists every provider that works for the customer\'s country, amount and currency (recommended). Enter a provider id such as `wert`, `stripe` or `transak` to send customers straight to it. [Provider ids and status](https://api.paygate.to/control/provider-status)',
                    ],
                ],
                'tolerance' => [
                    'select', [
                        'label' => 'Fees tolerance',
                        'description' => 'Share of the invoice total (in USD) that must arrive for the invoice to be paid. Providers take a fee, and a lower amount means the customer changed the payment link.',
                        'multiOptions' => [
                            '0.60' => '60% (default)',
                            '0.70' => '70%',
                            '0.80' => '80%',
                            '0.90' => '90%',
                            '0' => 'Disabled: accept any amount',
                        ],
                    ],
                ],
                'api_domain' => [
                    'text', [
                        'label' => 'Dedicated API endpoint (optional)',
                        'description' => 'Your free DDoS-protected endpoint, for example api-yourshop-1a2b3c.paygate.to. Leave empty to use api.paygate.to. [Get one](https://paygate.to/white-label-api-custom-domain-guide/#dedicated-endpoint)',
                    ],
                ],
                'checkout_domain' => [
                    'text', [
                        'label' => 'Checkout domain (optional)',
                        'description' => 'Your white-label checkout domain. Leave empty to use checkout.paygate.to.',
                    ],
                ],
                'theme' => [
                    'text', [
                        'label' => 'Checkout colour (optional)',
                        'description' => 'Hex code or CSS colour name for the hosted page, for example #7c3aed.',
                    ],
                ],
                'logo_url' => [
                    'text', [
                        'label' => 'Checkout logo URL (optional)',
                        'description' => 'Your logo on the hosted page (https URL).',
                    ],
                ],
            ],
        ];
    }

    /**
     * The "Pay" page: creates this invoice's PayGate.to wallet and sends the customer to the checkout.
     */
    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);
        $invoiceService = $this->di['mod_service']('Invoice');
        $invoice = $invoiceService->toApiArray($invoiceModel, true);

        $total = $invoiceService->getTotalWithTax($invoiceModel);
        $currency = strtoupper((string) $invoice['currency']);
        $email = (string) ($invoice['buyer']['email'] ?? '');
        if ($total <= 0) {
            return self::pgError(__trans('This invoice has nothing to pay.'));
        }
        if ($invoiceService->isInvoiceTypeDeposit($invoiceModel)) {
            // Card providers take their fee from the payment, so the full deposit would never arrive.
            return self::pgError(__trans('Funds cannot be added by card. Please choose another payment method.'));
        }

        $callback = $this->pgCallbackUrl((int) $invoice['id']);
        $wallet = $this->pgRequest('GET', 'https://' . $this->pgApiHost() . '/control/wallet.php?address=' . rawurlencode(trim((string) $this->config['wallet'])) . '&callback=' . rawurlencode($callback));
        if ($wallet === null || !isset($wallet['address_in']) || !is_string($wallet['address_in']) || $wallet['address_in'] === '') {
            return self::pgError(__trans('Payment could not be started. Please try again in a moment.'));
        }

        $provider = trim((string) ($this->config['provider'] ?? ''));
        $query = 'address=' . self::pgKeepEncoded($wallet['address_in'])
            . '&amount=' . rawurlencode(self::pgAmount($total))
            . ($provider !== '' ? '&provider=' . rawurlencode($provider) : '')
            . '&email=' . rawurlencode($email)
            . '&currency=' . rawurlencode($currency);
        if ($provider === '') {
            foreach (['theme' => 'theme', 'logo_url' => 'logo'] as $setting => $param) {
                $value = trim((string) ($this->config[$setting] ?? ''));
                if ($value !== '') {
                    $query .= '&' . $param . '=' . rawurlencode($value);
                }
            }
            $checkoutDomain = trim((string) ($this->config['checkout_domain'] ?? ''));
            if ($checkoutDomain !== '') {
                $query .= '&domain=' . rawurlencode($this->pgCheckoutHost());
            }
        }
        $url = 'https://' . $this->pgCheckoutHost() . '/' . ($provider !== '' ? 'process-payment.php' : 'pay.php') . '?' . $query;

        return $this->pgRedirectHtml($url, __trans('Pay by card'));
    }

    /**
     * PayGate.to's callback: verified, then the invoice is paid when enough arrived.
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        $this->pgRun($api_admin, (int) $id, fn () => $this->handleCallback($api_admin, (int) $id, (array) $data, (int) $gateway_id));
    }

    private function handleCallback($api_admin, int $id, array $data, int $gatewayId): void
    {
        [$invoice, $params] = $this->pgAuthenticate($api_admin, $id, $data, $gatewayId);
        if ($invoice === null) {
            return;
        }
        $txid = trim((string) $params['txid_in']);
        $coin = strtolower((string) $params['coin']);

        if (($params['pending'] ?? '0') === '1') {
            $this->pgFinish($api_admin, $id, 'pending', sprintf('Unconfirmed payment of %s %s seen; waiting for confirmation.', $params['value_coin'], $coin));

            return;
        }
        if ($this->pgAlreadySettled($gatewayId, (int) $invoice->id, $txid, $id)) {
            $this->pgFinish($api_admin, $id, 'duplicate', 'Repeated callback for ' . $txid . ', already handled.');

            return;
        }
        if (!self::pgPaysUs($params, [(string) $this->config['wallet']])) {
            $this->pgFinish($api_admin, $id, 'wrong_payout', sprintf('Received %s %s, but the payout did not go to the wallet configured here. The invoice was not paid.', $params['value_coin'], $coin));

            return;
        }

        // Payouts arrive in USDC or USDT; some providers pay out in POL, ETH or BNB, priced in USD here.
        if (preg_match('/_(usdc|usdt)$/', $coin)) {
            $received = (float) $params['value_coin'];
        } elseif (isset(self::NATIVE_PAYOUTS[$coin])) {
            $info = $this->pgRequest('GET', 'https://' . $this->pgApiHost() . '/crypto/' . self::NATIVE_PAYOUTS[$coin] . '/info.php');
            if ($info === null || !isset($info['prices']['USD']) || !is_numeric($info['prices']['USD'])) {
                // Temporary: PayGate.to calls again and the next attempt prices it.
                throw new Payment_Exception('Could not price :coin in USD to check the amount', [':coin' => $coin]);
            }
            $received = (float) $params['value_coin'] * (float) $info['prices']['USD'];
        } else {
            $this->pgFinish($api_admin, $id, 'wrong_coin', sprintf('Received %s %s on the card payment address: check the order before releasing it.', $params['value_coin'], $coin));

            return;
        }

        // Compare with the invoice total in USD (the customer can see and edit the payment link).
        $invoiceService = $this->di['mod_service']('Invoice');
        $total = $invoiceService->getTotalWithTax($invoice);
        $currency = strtoupper((string) $invoice->currency);
        $tolerance = isset($this->config['tolerance']) && is_numeric($this->config['tolerance']) ? max(0.0, min(1.0, (float) $this->config['tolerance'])) : 0.60;
        if ($invoiceService->isInvoiceTypeDeposit($invoice)) {
            $tolerance = max($tolerance, 0.98); // added funds are credited in full, so they must arrive in full
        }
        if ($tolerance > 0) {
            $expectedUsd = $total;
            if ($currency !== 'USD') {
                $conversion = $this->pgRequest('GET', 'https://' . $this->pgApiHost() . '/control/convert.php?value=' . rawurlencode(self::pgAmount($total)) . '&from=' . rawurlencode(strtolower($currency)));
                if ($conversion === null || !isset($conversion['value_coin']) || !is_numeric($conversion['value_coin'])) {
                    // Temporary: PayGate.to calls again and the next attempt converts.
                    throw new Payment_Exception('Could not convert :currency to USD to check the amount', [':currency' => $currency]);
                }
                $expectedUsd = (float) $conversion['value_coin'];
            }
            if ($received < $tolerance * $expectedUsd) {
                $this->pgFinish($api_admin, $id, 'underpaid', sprintf('Received %s %s (%s USD), the invoice total is %s USD (%s %s); at least %d%% is required. The invoice was not paid.', $params['value_coin'], $coin, number_format($received, 2, '.', ''), number_format($expectedUsd, 2, '.', ''), number_format($total, 2, '.', ''), $currency, (int) round($tolerance * 100)));

                return;
            }
        }

        $this->pgPayInvoice($api_admin, $id, $invoice, $txid, sprintf('Received %s %s. TXID in: %s, payout: %s', $params['value_coin'], $coin, $txid, (string) ($params['txid_out'] ?? '')));
    }

    // ------------------------------------------------------------------
    // Shared PayGate.to helpers (identical in PayGateCard and PayGateCrypto)
    // ------------------------------------------------------------------

    /** Names PayGate.to adds to the callback; never used for our own parameters. */
    private const PAYGATE_PARAMS = ['value_coin', 'coin', 'txid_in', 'txid_out', 'address_in', 'value_forwarded_coin', 'address_out', 'pending'];

    /** Outcomes that are final: such a transaction is never evaluated again. */
    private const FINAL_STATUSES = ['paid', 'underpaid', 'wrong_coin', 'extra_payment', 'wrong_payout'];

    /** Error codes shown in Invoices > Transactions for payments that need the merchant's attention. */
    private const ERROR_CODES = ['underpaid' => 3001, 'wrong_coin' => 3002, 'extra_payment' => 3003, 'wrong_payout' => 3004];

    /** Name of the database lock held while a callback is handled. */
    private ?string $pgLockName = null;

    private function pgApiHost(): string
    {
        return $this->pgHost((string) ($this->config['api_domain'] ?? ''), 'api.paygate.to');
    }

    private function pgCheckoutHost(): string
    {
        return $this->pgHost((string) ($this->config['checkout_domain'] ?? ''), 'checkout.paygate.to');
    }

    private function pgHost(string $value, string $default): string
    {
        $host = strtolower(trim($value));
        $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host);
        $host = rtrim(explode('/', $host, 2)[0], '.');
        if ($host === '' || !preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            return $default;
        }

        return $host;
    }

    /** GET or POST to the PayGate.to API; returns the decoded JSON object, or null on any failure. */
    private function pgRequest(string $method, string $url, ?array $json = null): ?array
    {
        try {
            $options = ['timeout' => 30, 'max_redirects' => 0, 'headers' => ['Accept' => 'application/json', 'User-Agent' => 'FOSSBilling-PayGate/' . self::MODULE_VERSION]];
            if ($json !== null) {
                $options['json'] = $json;
            }
            $client = isset($this->di['http_client']) ? $this->di['http_client'] : Symfony\Component\HttpClient\HttpClient::create();
            $response = $client->request($method, $url, $options);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $data = json_decode($response->getContent(false), true);
        } catch (Throwable $e) {
            // Never log URLs: the callback URL inside them carries the invoice signature.
            $this->pgLog('PayGate.to request to ' . parse_url($url, PHP_URL_HOST) . ' failed: ' . preg_replace('#[a-z][a-z0-9+.-]*://\S+#i', '[url]', $e->getMessage()));

            return null;
        }
        if (!is_array($data) || (isset($data['status']) && is_string($data['status']) && strtolower($data['status']) === 'error')) {
            return null;
        }

        return $data;
    }

    /** Values PayGate.to returns already percent-encoded are passed through; anything else is encoded. */
    private static function pgKeepEncoded(string $value): string
    {
        if (preg_match('/%[0-9A-Fa-f]{2}/', $value) && preg_match('/^(?:[A-Za-z0-9._~-]|%[0-9A-Fa-f]{2})+$/', $value)) {
            return $value;
        }

        return rawurlencode($value);
    }

    /** Plain decimal string for amounts sent to PayGate.to. */
    private static function pgAmount(float $value, int $decimals = 2): string
    {
        $text = number_format($value, $decimals, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /**
     * HMAC with this installation's secret salt (config.php, info.salt), so callback parameters
     * cannot be changed by anyone outside the server.
     */
    private function pgHmac(string $data): string
    {
        $salt = (string) FOSSBilling\Config::getProperty('info.salt', '');
        if (strlen($salt) < 16) {
            throw new Payment_Exception('The info.salt setting in config.php is missing or too short.');
        }

        return hash_hmac('sha256', 'paygate|' . $data, $salt);
    }

    /** This gateway's id (set by FOSSBilling 0.8+, read from the callback URL on older versions). */
    private function pgGatewayId(): int
    {
        if (!empty($this->config['gateway_id'])) {
            return (int) $this->config['gateway_id'];
        }
        parse_str((string) parse_url((string) ($this->config['notify_url'] ?? ''), PHP_URL_QUERY), $query);

        return (int) ($query['gateway_id'] ?? 0);
    }

    /** Signature binding a callback to this gateway and invoice. */
    private function pgInvoiceToken(int $invoiceId): string
    {
        return $this->pgHmac('invoice|' . $this->pgGatewayId() . '|' . $invoiceId);
    }

    /** This invoice's callback URL: FOSSBilling's ipn.php plus the invoice signature. */
    private function pgCallbackUrl(int $invoiceId, array $extra = []): string
    {
        $url = (string) $this->config['notify_url'];
        $url .= (str_contains($url, '?') ? '&' : '?') . 'pg_token=' . $this->pgInvoiceToken($invoiceId);
        foreach ($extra as $name => $value) {
            $url .= '&' . rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }

        return $url;
    }

    /** The undecoded query string of the stored callback request. */
    private static function pgRawQuery(array $server): string
    {
        if (isset($server['REQUEST_URI'])) {
            $uri = (string) $server['REQUEST_URI'];
            $hash = strpos($uri, '#');
            if ($hash !== false) {
                $uri = substr($uri, 0, $hash);
            }
            $mark = strpos($uri, '?');

            return $mark === false ? '' : substr($uri, $mark + 1);
        }

        return (string) ($server['QUERY_STRING'] ?? '');
    }

    /** Query parameters; a repeated name keeps its last value (PayGate.to's, which comes last). */
    private static function pgParseQuery(string $raw): array
    {
        $params = [];
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            $params[urldecode($eq === false ? $pair : substr($pair, 0, $eq))] = $eq === false ? '' : urldecode(substr($pair, $eq + 1));
        }

        return $params;
    }

    /**
     * Verify PayGate.to's RSA signature (X-PayGate-Signature, X-PayGate-Key-Id) over the registered
     * callback URL (scheme, host and path of ipn.php) followed by the raw query string as received.
     * Returns an empty string when valid, otherwise the reason.
     */
    private function pgVerifySignature(array $server): string
    {
        $signature = trim((string) ($server['HTTP_X_PAYGATE_SIGNATURE'] ?? ''));
        if ($signature === '') {
            return 'signature_absent';
        }
        if (!function_exists('openssl_verify')) {
            return 'openssl_unavailable';
        }
        $keyId = trim((string) ($server['HTTP_X_PAYGATE_KEY_ID'] ?? '')) ?: 'v1';
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $keyId)) {
            return 'unknown_key_id';
        }
        $pemFile = __DIR__ . '/keys/paygate_callback_public_' . $keyId . '.pem';
        $pem = is_readable($pemFile) ? file_get_contents($pemFile) : false;
        if (!is_string($pem) || $pem === '') {
            return 'unknown_key_id';
        }
        $publicKey = openssl_pkey_get_public($pem);
        if ($publicKey === false) {
            return 'bad_public_key';
        }
        $raw = base64_decode($signature, true);
        if ($raw === false || $raw === '') {
            return 'signature_not_base64';
        }
        $notifyUrl = (string) $this->config['notify_url'];
        $base = substr($notifyUrl, 0, strcspn($notifyUrl, '?#'));
        $query = self::pgRawQuery($server);
        $signed = $query === '' ? $base : $base . '?' . $query;
        $result = openssl_verify($signed, $raw, $publicKey, OPENSSL_ALGO_SHA256);

        return $result === 1 ? '' : ($result === -1 ? 'openssl_error' : 'signature_mismatch');
    }

    /**
     * Runs a callback handler. Errors are recorded on the transaction (FOSSBilling 0.7 leaves it
     * "received" otherwise) and the invoice lock is always released.
     */
    private function pgRun($api_admin, int $id, callable $handler): void
    {
        try {
            $handler();
        } catch (Throwable $e) {
            try {
                $api_admin->invoice_transaction_update([
                    'id' => $id,
                    'status' => Model_Transaction::STATUS_ERROR,
                    'error' => $e->getMessage(),
                    'error_code' => (int) $e->getCode(),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (Throwable $ignored) {
                // The original error is the one that matters.
            }

            throw $e;
        } finally {
            if ($this->pgLockName !== null) {
                $this->di['db']->getCell('SELECT RELEASE_LOCK(:name)', [':name' => $this->pgLockName]);
                $this->pgLockName = null;
            }
        }
    }

    /**
     * One callback per invoice at a time: PayGate.to may deliver the same callback more than once,
     * and simultaneous deliveries must not credit the client twice.
     */
    private function pgLock(int $invoiceId): void
    {
        $name = 'paygate:invoice:' . $invoiceId;
        if ((int) $this->di['db']->getCell('SELECT GET_LOCK(:name, 20)', [':name' => $name]) !== 1) {
            throw new Payment_Exception('Another PayGate.to callback for invoice #:id is being handled; this one will be retried', [':id' => $invoiceId]);
        }
        $this->pgLockName = $name;
    }

    /**
     * Common callback checks. Returns [invoice model, PayGate.to parameters], [null, []] when this
     * transaction already has a final outcome, or throws.
     */
    private function pgAuthenticate($api_admin, int $id, array $data, int $gatewayId): array
    {
        $server = is_array($data['server'] ?? null) ? $data['server'] : [];
        $reason = $this->pgVerifySignature($server);
        if ($reason !== '') {
            throw new Payment_Exception('PayGate.to callback rejected: :reason', [':reason' => $reason]);
        }

        $params = self::pgParseQuery(self::pgRawQuery($server));
        $invoiceId = (int) ($params['invoice_id'] ?? 0);
        if ((int) ($params['gateway_id'] ?? 0) !== $gatewayId || $this->pgGatewayId() !== $gatewayId) {
            throw new Payment_Exception('PayGate.to callback rejected: gateway does not match');
        }
        if ($invoiceId < 1 || !hash_equals($this->pgInvoiceToken($invoiceId), (string) ($params['pg_token'] ?? ''))) {
            throw new Payment_Exception('PayGate.to callback rejected: invoice signature is invalid');
        }

        $this->pgLock($invoiceId);
        $tx = $api_admin->invoice_transaction_get(['id' => $id]);
        if (!empty($tx['invoice_id']) && (int) $tx['invoice_id'] !== $invoiceId) {
            throw new Payment_Exception('PayGate.to callback rejected: invoice does not match the transaction');
        }
        if (($tx['status'] ?? '') === Model_Transaction::STATUS_PROCESSED && in_array($tx['txn_status'] ?? '', self::FINAL_STATUSES, true)) {
            return [null, []]; // processed again from the admin area: the recorded outcome stands
        }
        $invoice = $this->di['db']->load('Invoice', $invoiceId);
        if (!$invoice instanceof Model_Invoice) {
            throw new Payment_Exception('Invoice #:id not found', [':id' => $invoiceId]);
        }

        $txid = trim((string) ($params['txid_in'] ?? ''));
        if ($txid === '' || !isset($params['value_coin'], $params['coin'])) {
            throw new Payment_Exception('PayGate.to callback is missing payment data');
        }

        $api_admin->invoice_transaction_update([
            'id' => $id,
            'invoice_id' => $invoiceId,
            'txn_id' => $txid,
            'type' => self::TX_TYPE,
        ]);

        return [$invoice, $params];
    }

    /**
     * True if another transaction already settled this incoming blockchain transaction for this
     * invoice. One blockchain transaction can pay several invoices (exchange batch withdrawals),
     * so the invoice is part of the check.
     */
    private function pgAlreadySettled(int $gatewayId, int $invoiceId, string $txid, int $currentId): bool
    {
        $existing = $this->di['db']->findOne(
            'Transaction',
            'gateway_id = :gateway AND invoice_id = :invoice AND txn_id = :txn AND id != :id AND status = :processed AND txn_status IN (\'' . implode('\', \'', self::FINAL_STATUSES) . '\')',
            [':gateway' => $gatewayId, ':invoice' => $invoiceId, ':txn' => $txid, ':id' => $currentId, ':processed' => Model_Transaction::STATUS_PROCESSED]
        );

        return $existing instanceof Model_Transaction;
    }

    /**
     * Close the transaction (shown in Admin > Invoices > Transactions). Payments that need the
     * merchant's attention carry an error code: 3001 underpaid, 3002 wrong coin, 3003 extra payment.
     */
    private function pgFinish($api_admin, int $id, string $txnStatus, string $note, ?float $amount = null, ?string $currency = null): void
    {
        $update = [
            'id' => $id,
            'txn_status' => $txnStatus,
            'status' => Model_Transaction::STATUS_PROCESSED,
            'error' => isset(self::ERROR_CODES[$txnStatus]) ? $note : '',
            'error_code' => self::ERROR_CODES[$txnStatus] ?? 0,
            'note' => $note,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($amount !== null) {
            $update['amount'] = $amount;
            $update['currency'] = $currency;
        }
        $api_admin->invoice_transaction_update($update);
    }

    /**
     * When PayGate.to lists the payout addresses (address_out), at least 90% of the payment must go
     * to the wallets configured here. Without this, a leaked callback URL could be registered with
     * someone else's wallet.
     *
     * @param string[] $wallets
     */
    private static function pgPaysUs(array $params, array $wallets): bool
    {
        if (!isset($params['address_out']) || trim((string) $params['address_out']) === '') {
            return true; // nothing to compare with
        }
        $shares = json_decode((string) $params['address_out'], true);
        if (!is_array($shares)) {
            return false;
        }
        $ours = [];
        foreach ($wallets as $wallet) {
            if (trim((string) $wallet) !== '') {
                $ours[] = strtolower(trim((string) $wallet));
            }
        }
        $share = 0.0;
        foreach ($shares as $address => $value) {
            if (in_array(strtolower(trim((string) $address)), $ours, true) && is_numeric($value)) {
                $share += (float) $value;
            }
        }

        return $share >= 0.9;
    }

    /** Credit the client with the invoice total and pay the invoice, as FOSSBilling's own gateways do. */
    private function pgPayInvoice($api_admin, int $id, Model_Invoice $invoice, string $txid, string $note): void
    {
        $invoiceService = $this->di['mod_service']('Invoice');
        $total = $invoiceService->getTotalWithTax($invoice);
        $apiInvoice = $api_admin->invoice_get(['id' => $invoice->id]);

        // Read the status from the database: the invoice may have been paid or cancelled in the meantime.
        $status = (string) $this->di['db']->getCell('SELECT status FROM invoice WHERE id = :id', [':id' => $invoice->id]);
        if ($status !== Model_Invoice::STATUS_UNPAID) {
            $this->pgFinish($api_admin, $id, 'extra_payment', 'The invoice is ' . $status . ', so this payment was not applied: ' . $note . '. Refund the client or add the amount to their balance.');

            return;
        }

        $api_admin->client_balance_add_funds([
            'id' => $apiInvoice['client']['id'],
            'amount' => $total,
            'description' => self::TX_TYPE . ' transaction ' . $txid,
            'type' => self::TX_TYPE,
            'rel_id' => $id, // this transaction (blockchain hashes are longer than the column)
        ]);
        if ($invoiceService->isInvoiceTypeDeposit($invoice)) {
            $invoiceService->markAsPaid($invoice);
        } else {
            if (!$invoice->approved) {
                $invoiceService->approveInvoice($invoice, ['use_credits' => false]);
            }
            $api_admin->invoice_pay_with_credits(['id' => $invoice->id]);
        }

        $this->pgFinish($api_admin, $id, 'paid', $note, $total, $apiInvoice['currency'] ?? null);
    }

    private function pgLog(string $message): void
    {
        try {
            if (isset($this->di['logger'])) {
                $this->di['logger']->warn($message); // "warning" is not a priority on FOSSBilling 0.7
            }
        } catch (Throwable $e) {
            // Logging must never break a payment.
        }
    }

    private static function pgEscape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** A button to the payment page, followed automatically when FOSSBilling asks for it. */
    private function pgRedirectHtml(string $url, string $label): string
    {
        $html = '<a class="btn btn-primary" id="paygate-pay" href="' . self::pgEscape($url) . '" rel="noreferrer">' . self::pgEscape($label) . '</a>';
        if (!empty($this->config['auto_redirect'])) {
            $html .= '<script>window.location.href = ' . json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
        }

        return $html;
    }

    private static function pgError(string $message): string
    {
        return '<div class="alert alert-danger">' . self::pgEscape($message) . '</div>';
    }
}
