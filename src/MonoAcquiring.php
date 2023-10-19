<?php


namespace App\Service;


class MonoAcquiring
{
    private ?string $token;
    const API_URL = 'https://api.monobank.ua';

    /**
     * @param string|null $token Токен з особистого кабінету https://fop.monobank.ua/ або тестовий токен з https://api.monobank.ua/
     */
    public function __construct(string $token = null)
    {
        $this->token = $token ?? $_ENV['MONOBANK_TOKEN'] ?? null;
    }

    private static function makeSureInvoiceNotEmpty($invoice_id)
    {
        if (empty($invoice_id)) {
            throw new \InvalidArgumentException('invoiceId is empty. Must be defined',500);
        }
    }

    /**
     * General POST/GET request to API using token
     * @param string $path
     * @param array $options
     * @param bool $method_post default POST
     * @throws \Exception
     */
    public function api(string $path, array $options, $method_post = true): array
    {
        $request = json_encode($options);
        $curl_options = [
            CURLOPT_URL => self::API_URL . $path,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-Token: ' . $this->token,
                'Content-Type: application/json',
            ],
        ];

        if ($method_post)
        {
            $curl_options[CURLOPT_POST] = true;
            $curl_options[CURLOPT_POSTFIELDS] = $request;
        }

        $ch = curl_init();
        curl_setopt_array($ch, $curl_options);
        $response_content = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$response_content) {
            throw new \Exception('Empty response from Mono API',500);
        }

        $data = json_decode($response_content,true);
        if (!$data) {
            throw new \Exception("Can not decode json response from Mono: $response_content", 500);
        }

        if ($http_code == 200) {
            return $data;
        }

        if (isset($data['errorDescription'])) {
            throw new \Exception($data['errorDescription'], $http_code);
        }

        if (isset($data['errCode']) && isset($data['errText'])) {
            throw new \Exception("errText: $data[errText]; errCode: $data[errCode]", $http_code);
        }

        throw new \Exception("Unknown error response: $response_content", $http_code);
    }

    /**
     * General GET request to API using token
     * @param string $path
     * @throws \Exception
     */
    public function apiGet(string $path): array
    {
        return $this->api($path, [], false);
    }

    /**
     * Отримує дані про мерчант
     * @return array ['merchantId' => ..., 'merchantName' => ..., 'edrpou' => ..., ]
     * @throws \Exception
     * @link https://api.monobank.ua/docs/acquiring.html#/paths/~1api~1merchant~1details/get Так отримуються деталі мерчанту
     */
    public function getMerchantDetails(): array
    {
        return $this->apiGet('/api/merchant/details');
    }

    /**
     * Відкритий ключ для верифікації підписів
     * Отримання відкритого ключа для перевірки підпису, який включено у вебхуки. Ключ можна кешувати і робити запит на отримання нового, коли верифікація підпису з поточним ключем перестане працювати. Кожного разу робити запит на отримання ключа не треба
     * @link https://api.monobank.ua/docs/acquiring.html#/paths/~1api~1merchant~1pubkey/get
     * @throws \Exception
     */
    public function getPublicKey(): string
    {
        $data = $this->apiGet('/api/merchant/pubkey');
        if (!isset($data['key'])) {
            throw new \Exception('Invalid response from Mono API',500);
        }
        return $data['key'];
    }

    /**
     * Створення рахунку для оплати
     * @param int $amount Сума оплати у мінімальних одиницях (копійки для гривні)
     * @param array $options Додаткові параметри (Див. посилання)
     * @throws \Exception
     * @link https://api.monobank.ua/docs/acquiring.html#/paths/~1api~1merchant~1invoice~1create/post
     */
    public function createPayment(int $amount, array $options = []): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be a natural number',500);
        }

        $options['amount'] = $amount;
        return $this->api('/api/merchant/invoice/create', $options);
    }

    /**
     * Статус рахунку
     * Метод перевірки статусу рахунку при розсинхронізації з боку продавця або відсутності webHookUrl при створенні рахунку.
     * @param string $invoice_id ID рахунку
     * @link https://api.monobank.ua/docs/acquiring.html#/paths/~1api~1merchant~1invoice~1status?invoiceId=%7BinvoiceId%7D/get
     * @throws \Exception
     */
    public function getPaymentStatus(string $invoice_id): array
    {
        self::makeSureInvoiceNotEmpty($invoice_id);
        return $this->apiGet("/api/merchant/invoice/status?invoiceId=$invoice_id");
    }

    /**
     * Скасування успішної оплати рахунку
     * @param string $invoice_id ID рахунку
     * @param array $options Додаткові параметри (Див. посилання)
     * @link https://api.monobank.ua/docs/acquiring.html#/paths/~1api~1merchant~1invoice~1cancel/post
     * @throws \Exception
     */
    public function refundPayment(string $invoice_id, array $options = []): array
    {
        self::makeSureInvoiceNotEmpty($invoice_id);
        $options['invoiceId'] = $invoice_id;
        return $this->api("/api/merchant/invoice/cancel", $options);
    }

    /**
     * Інвалідація рахунку, якщо за ним ще не було здіснено оплати
     * @param string $invoice_id ID рахунку
     * @link https://api.monobank.ua/docs/acquiring.html#/paths/~1api~1merchant~1invoice~1remove/post
     * @throws \Exception
     */
    public function invalidatePayment(string $invoice_id): array
    {
        self::makeSureInvoiceNotEmpty($invoice_id);
        $options['invoiceId'] = $invoice_id;
        return $this->api("/api/merchant/invoice/remove", $options);
    }

    /**
     * Фіналізація суми холду
     * Фінальна сума списання має бути не більшою суми холду
     * @param string $invoice_id Ідентифікатор рахунку
     * @param int|null $amount Сума у мінімальних одиницях, якщо бажаєте змінити суму списання
     * @return array
     * @throws \Exception
     * @link https://api.monobank.ua/docs/acquiring.html#/paths/~1api~1merchant~1invoice~1finalize/post
     */
    public function finalizePayment(string $invoice_id, int $amount = null): array
    {
        self::makeSureInvoiceNotEmpty($invoice_id);
        $options['invoiceId'] = $invoice_id;
        if (isset($amount)) {
            $options['amount'] = $amount;
        }

        return $this->api("/api/merchant/invoice/finalize", $options);
    }

    /**
     * Розширена інформація про успішну оплату
     * Дані про успішну оплату, якщо вона була здійснена
     * @param string $invoice_id Ідентифікатор рахунку
     * @throws \Exception
     *@link https://api.monobank.ua/docs/acquiring.html#/paths/~1api~1merchant~1invoice~1payment-info?invoiceId=%7BinvoiceId%7D/get
     */
    public function getPaymentSuccessDetails(string $invoice_id): array
    {
        self::makeSureInvoiceNotEmpty($invoice_id);
        return $this->apiGet("/api/merchant/invoice/payment-info?invoiceId=$invoice_id");
    }

    /**
     * Виписка за період
     * Список платежів за вказаний період
     * @param int $timestamp_from UTC Unix timestamp
     * @param int|null $timestamp_to UTC Unix timestamp
     * @throws \Exception
     * @link https://api.monobank.ua/docs/acquiring.html#/paths/~1api~1merchant~1statement/get
     */
    public function getPaymentsList(int $timestamp_from, int $timestamp_to = null): array
    {
        $query = "from=$timestamp_from" . ($timestamp_to ? "&to=$timestamp_to" : '');

        $data = $this->apiGet("/api/merchant/statement?$query");
        return $data['list'] ?? [];
    }

    /**
     * Перевіряє чи можна довіряти даним з вебхуку
     * @param string|null $content Текст запиту (json). Якщо агрумент не переданий, намагаємось отримати через функцію file_get_contents('php://input')
     * @param string|null $public_key_base64 Якщо агрумент не переданий, намагаємось отримати через метод $this->getPublicKey()
     * @param string|null $xsign_base64 Якщо агрумент не переданий, намагаємось отримати з хедера X-Sign
     * @return bool
     * @throws \Exception
     */
    public function verifyWebhook(string $content = null, string $public_key_base64 = null, string $xsign_base64 = null): bool
    {
        $public_key_base64 = $public_key_base64 ?? $this->getPublicKey();
        if(empty($public_key_base64)) {
            throw new \Exception('Public key is empty');
        }

        $xsign_base64 = $xsign_base64 ?? $_SERVER['HTTP_X_SIGN'] ?? null;
        if (empty($xsign_base64)) {
            throw new \Exception('X-Sign header value is empty');
        }

        $data = $content ?? file_get_contents('php://input');
        $signature = base64_decode($xsign_base64);
        $public_key = openssl_get_publickey(base64_decode($public_key_base64));

        $result = openssl_verify($data, $signature, $public_key, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }
}