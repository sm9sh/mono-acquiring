<?php

error_reporting(E_ALL);
$deprecations = [];
set_error_handler(function ($severity, $message) use (&$deprecations) {
    if ($severity !== E_DEPRECATED && $severity !== E_USER_DEPRECATED) {
        return false;
    }
    $deprecations[$message] = true;
    return true;
});
require dirname(__DIR__) . '/src/MonoAcquiring.php';

class RecordingMonoAcquiring extends MonoAcquiring\MonoAcquiring
{
    public array $calls = [];
    public array $responses = [];

    public function api(string $path, array $options, $method_post = true): array
    {
        $this->calls[] = [$path, $options, $method_post];
        return $this->responses[$path] ?? ['ok' => true];
    }
}

$checks = 0;
function checkCompatibility($condition, $message)
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}

$original_env = $_ENV;
$original_server = $_SERVER;
try {
    $_ENV['MONOBANK_TOKEN'] = 'fixture-environment-token';
    $token_property = new ReflectionProperty(MonoAcquiring\MonoAcquiring::class, 'token');
    $token_property->setAccessible(true);
    $client = new RecordingMonoAcquiring();
    checkCompatibility($token_property->getValue($client) === 'fixture-environment-token', 'Default token fallback');
    $client = new RecordingMonoAcquiring(null);
    checkCompatibility($token_property->getValue($client) === 'fixture-environment-token', 'Null token fallback');
    $client = new RecordingMonoAcquiring('fixture-explicit-token');
    checkCompatibility($token_property->getValue($client) === 'fixture-explicit-token', 'Explicit token precedence');
    unset($_ENV['MONOBANK_TOKEN']);
    checkCompatibility($token_property->getValue(new RecordingMonoAcquiring()) === null, 'Missing token stays null');

    foreach ([null, 0, 12345] as $amount) {
        $client->finalizePayment('fixture-invoice', $amount);
        $options = ['invoiceId' => 'fixture-invoice'];
        if ($amount !== null) {
            $options['amount'] = $amount;
        }
        checkCompatibility(end($client->calls) === ['/api/merchant/invoice/finalize', $options, true], 'Finalize amount shape');
    }
    $client->finalizePayment('fixture-invoice');
    checkCompatibility(end($client->calls) === ['/api/merchant/invoice/finalize', ['invoiceId' => 'fixture-invoice'], true], 'Omitted finalize amount');
    $call_count = count($client->calls);
    try {
        $client->finalizePayment('');
        throw new RuntimeException('Empty invoice accepted');
    } catch (InvalidArgumentException $exception) {
        checkCompatibility(count($client->calls) === $call_count, 'Empty invoice rejected before API');
    }

    foreach ([null, 0, 200] as $timestamp_to) {
        $path = '/api/merchant/statement?from=100' . ($timestamp_to ? '&to=' . $timestamp_to : '');
        $client->responses[$path] = ['list' => [['invoiceId' => 'fixture-invoice']]];
        checkCompatibility($client->getPaymentsList(100, $timestamp_to) === [['invoiceId' => 'fixture-invoice']], 'Statement result');
        checkCompatibility(end($client->calls) === [$path, [], false], 'Statement query shape');
    }
    checkCompatibility($client->getPaymentsList(100) === [['invoiceId' => 'fixture-invoice']], 'Omitted statement end');

    // Keys are generated in memory for this test; no request reaches a bank.
    $private_key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    checkCompatibility($private_key !== false, 'Generate test key; check OPENSSL_CONF if this fails');
    $key_details = openssl_pkey_get_details($private_key);
    $public_key = base64_encode($key_details['key']);
    $content = '{"invoiceId":"fixture-invoice","status":"success"}';
    checkCompatibility(openssl_sign($content, $signature, $private_key, OPENSSL_ALGO_SHA256), 'Sign fixture');
    $signature_base64 = base64_encode($signature);
    checkCompatibility($client->verifyWebhook($content, $public_key, $signature_base64), 'Valid webhook signature');
    checkCompatibility(!$client->verifyWebhook($content . ' ', $public_key, $signature_base64), 'Modified payload rejected');
    $client->responses['/api/merchant/pubkey'] = ['key' => $public_key];
    $_SERVER['HTTP_X_SIGN'] = $signature_base64;
    checkCompatibility($client->verifyWebhook($content), 'Omitted key and header fallback');
    checkCompatibility($client->verifyWebhook($content, null, null), 'Null key and header fallback');
    checkCompatibility(end($client->calls) === ['/api/merchant/pubkey', [], false], 'Public key request uses stub');
    checkCompatibility(openssl_sign('', $empty_signature, $private_key, OPENSSL_ALGO_SHA256), 'Sign empty CLI input');
    checkCompatibility($client->verifyWebhook(null, $public_key, base64_encode($empty_signature)), 'Null content reads empty CLI php input');
    unset($_SERVER['HTTP_X_SIGN']);
    try {
        $client->verifyWebhook($content, $public_key);
        throw new RuntimeException('Missing signature accepted');
    } catch (Exception $exception) {
        checkCompatibility($exception->getMessage() === 'X-Sign header value is empty', 'Missing signature rejected');
    }
} finally {
    $_ENV = $original_env;
    $_SERVER = $original_server;
}

foreach (array_keys($deprecations) as $message) {
    fwrite(STDERR, $message . PHP_EOL);
}
echo $checks . ' checks; ' . count($deprecations) . ' unique deprecations' . PHP_EOL;
exit($deprecations ? 1 : 0);