<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class AsaasPaymentService
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.asaas.base_url', 'https://api.asaas.com/v3'), '/');
        $this->apiKey = trim((string) config('services.asaas.api_key'));
        $this->timeout = max(5, (int) config('services.asaas.timeout', 20));
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->baseUrl !== '';
    }

    public function findOrCreateCustomer(
        int $userId,
        string $name,
        string $cpfCnpj,
        ?string $email = null,
        ?string $mobilePhone = null,
    ): array {
        $this->assertConfigured();

        $document = preg_replace('/\D+/', '', $cpfCnpj);
        if (! in_array(strlen($document), [11, 14], true)) {
            throw new RuntimeException('Informe um CPF ou CNPJ válido para o pagamento.');
        }

        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Informe o nome do pagador.');
        }

        $reference = 'petertecnet-user-' . $userId;

        $existing = $this->firstCustomer(['cpfCnpj' => $document]);
        if ($existing) {
            return $existing;
        }

        $existing = $this->firstCustomer(['externalReference' => $reference]);
        if ($existing) {
            if (preg_replace('/\D+/', '', (string) ($existing['cpfCnpj'] ?? '')) !== $document) {
                throw new RuntimeException('Identidade do pagador divergente no Asaas; operação bloqueada.');
            }

            return $existing;
        }

        $payload = [
            'name' => mb_substr($name, 0, 120),
            'cpfCnpj' => $document,
            'externalReference' => $reference,
            'notificationDisabled' => false,
        ];

        if ($email) {
            $payload['email'] = mb_substr(trim($email), 0, 190);
        }

        $phone = preg_replace('/\D+/', '', (string) $mobilePhone);
        if ($phone !== '') {
            $payload['mobilePhone'] = mb_substr($phone, 0, 20);
        }

        try {
            return $this->successfulJson(
                $this->request()->post('/customers', $payload),
                'Não foi possível preparar o cadastro do pagador no Asaas.'
            );
        } catch (ConnectionException $exception) {
            $recovered = $this->firstCustomer(['cpfCnpj' => $document]);
            if ($recovered) {
                return $recovered;
            }

            throw $exception;
        }
    }

    public function createOrRecoverPayment(
        string $customerId,
        string $method,
        float $value,
        string $dueDate,
        string $externalReference,
        string $description,
        ?string $successUrl = null,
    ): array {
        $this->assertConfigured();

        $existing = $this->firstPayment(['externalReference' => $externalReference]);
        if ($existing) {
            return $this->decoratePayment($existing);
        }

        $billingType = match (strtolower($method)) {
            'pix' => 'PIX',
            'card' => 'CREDIT_CARD',
            'boleto' => 'BOLETO',
            default => throw new RuntimeException('Forma de pagamento não suportada pelo Asaas.'),
        };

        $payload = [
            'customer' => $customerId,
            'billingType' => $billingType,
            'value' => round($value, 2),
            'dueDate' => $dueDate,
            'description' => mb_substr($description, 0, 500),
            'externalReference' => $externalReference,
        ];

        if ($billingType === 'BOLETO') {
            $payload['daysAfterDueDateToRegistrationCancellation'] = max(
                0,
                (int) config('services.asaas.boleto_days_after_due_date', 1)
            );
        }

        if ($successUrl) {
            $payload['callback'] = [
                'successUrl' => $successUrl,
                'autoRedirect' => true,
            ];
        }

        try {
            $remote = $this->successfulJson(
                $this->request()->post('/payments', $payload),
                'O Asaas não conseguiu iniciar a cobrança.'
            );
        } catch (ConnectionException $exception) {
            $recovered = $this->firstPayment(['externalReference' => $externalReference]);
            if ($recovered) {
                return $this->decoratePayment($recovered);
            }

            throw $exception;
        }

        return $this->decoratePayment($remote);
    }

    public function getPayment(string $paymentId): array
    {
        $this->assertConfigured();

        return $this->successfulJson(
            $this->request()->get('/payments/' . rawurlencode($paymentId)),
            'Não foi possível consultar a cobrança no Asaas.'
        );
    }

    public function listPaymentsByExternalReference(string $externalReference): array
    {
        $this->assertConfigured();

        return $this->listAll('/payments', ['externalReference' => $externalReference],
            'Não foi possível consultar as cobranças no Asaas.');
    }

    public function getPixQrCode(string $paymentId): array
    {
        $this->assertConfigured();

        return $this->successfulJson(
            $this->request()->get('/payments/' . rawurlencode($paymentId) . '/pixQrCode'),
            'Não foi possível gerar o QR Code PIX no Asaas.'
        );
    }

    public function getBoletoIdentification(string $paymentId): array
    {
        $this->assertConfigured();

        return $this->successfulJson(
            $this->request()->get('/payments/' . rawurlencode($paymentId) . '/identificationField'),
            'Não foi possível obter a linha digitável do boleto.'
        );
    }

    public function normalizeLocalStatus(array $remote): string
    {
        $status = strtoupper(trim((string) ($remote['status'] ?? 'PENDING')));
        $billingType = strtoupper(trim((string) ($remote['billingType'] ?? '')));

        if ($status === 'RECEIVED') {
            return 'paid';
        }

        // Cartão confirmado já representa autorização financeira concluída.
        // Para PIX aguardamos RECEIVED porque CONFIRMED pode representar
        // bloqueio cautelar/análise antes da disponibilidade final.
        if ($status === 'CONFIRMED' && $billingType === 'CREDIT_CARD') {
            return 'paid';
        }

        return match ($status) {
            'REFUNDED', 'REFUND_REQUESTED', 'REFUND_IN_PROGRESS' => 'refunded',
            'CHARGEBACK_REQUESTED', 'CHARGEBACK_DISPUTE' => 'charged_back',
            'OVERDUE', 'DELETED', 'CANCELED', 'CANCELLED' => 'failed',
            default => 'pending',
        };
    }

    private function decoratePayment(array $remote): array
    {
        $paymentId = trim((string) ($remote['id'] ?? ''));
        if ($paymentId === '') {
            throw new RuntimeException('O Asaas não retornou o identificador da cobrança.');
        }

        $billingType = strtoupper(trim((string) ($remote['billingType'] ?? '')));

        if ($billingType === 'PIX') {
            try {
                $remote['_pix'] = $this->getPixQrCode($paymentId);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        if ($billingType === 'BOLETO') {
            try {
                $remote['_boleto'] = $this->getBoletoIdentification($paymentId);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $remote;
    }

    private function firstCustomer(array $filters): ?array
    {
        $customers = $this->listAll('/customers', $filters, 'Não foi possível consultar o pagador no Asaas.');
        if (count($customers) > 1) {
            throw new RuntimeException('Consulta ambígua de clientes no Asaas; operação bloqueada.');
        }

        return $customers[0] ?? null;
    }

    private function firstPayment(array $filters): ?array
    {
        $payments = $this->listAll('/payments', $filters, 'Não foi possível consultar cobranças anteriores no Asaas.');
        $active = array_values(array_filter($payments, static fn (array $payment): bool => empty($payment['deleted'])));
        if (count($active) > 1) {
            throw new RuntimeException('Consulta ambígua de cobranças no Asaas; operação bloqueada.');
        }

        return $active[0] ?? null;
    }

    /**
     * Nunca confundir uma consulta parcial/malformada com ausência de cobrança.
     * Em especial, hasMore=true impede aceitar apenas a primeira página.
     */
    private function listAll(string $endpoint, array $filters, string $message): array
    {
        $offset = 0;
        $results = [];
        $seen = [];

        for ($page = 0; $page < 25; $page++) {
            $response = $this->successfulJson(
                $this->request()->get($endpoint, array_merge($filters, ['limit' => 20, 'offset' => $offset])),
                $message
            );

            if (! isset($response['data']) || ! is_array($response['data'])
                || ! array_is_list($response['data'])
                || ! array_key_exists('hasMore', $response) || ! is_bool($response['hasMore'])) {
                throw new RuntimeException('Paginação Asaas inconclusiva; operação bloqueada.');
            }

            foreach ($response['data'] as $item) {
                if (! is_array($item) || ! is_string($item['id'] ?? null) || trim($item['id']) === '') {
                    throw new RuntimeException('Registro remoto Asaas inválido; operação bloqueada.');
                }
                if (isset($seen[$item['id']])) {
                    throw new RuntimeException('Registro Asaas duplicado entre páginas; operação bloqueada.');
                }
                if (isset($filters['externalReference'])
                    && (! is_string($item['externalReference'] ?? null)
                        || $item['externalReference'] !== $filters['externalReference'])) {
                    throw new RuntimeException('Referência remota Asaas divergente; operação bloqueada.');
                }
                if (isset($filters['cpfCnpj'])
                    && preg_replace('/\D+/', '', (string) ($item['cpfCnpj'] ?? '')) !== $filters['cpfCnpj']) {
                    throw new RuntimeException('Documento remoto Asaas divergente; operação bloqueada.');
                }

                $seen[$item['id']] = true;
                $results[] = $item;
            }

            if ($response['hasMore'] === false) {
                return $results;
            }
            if ($response['data'] === []) {
                throw new RuntimeException('Asaas indicou próxima página vazia; operação bloqueada.');
            }
            $offset += count($response['data']);
        }

        throw new RuntimeException('Limite de páginas Asaas excedido; operação bloqueada.');
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withHeaders([
                'access_token' => $this->apiKey,
                'User-Agent' => 'PeterTecnet/1.0',
            ])
            ->connectTimeout(5)
            ->timeout($this->timeout)
            ->baseUrl($this->baseUrl);
    }

    private function successfulJson(Response $response, string $message): array
    {
        if ($response->successful()) {
            return (array) $response->json();
        }

        $providerMessage = collect((array) $response->json('errors', []))
            ->pluck('description')
            ->filter()
            ->take(2)
            ->implode(' ');

        throw new RuntimeException(trim($message . ($providerMessage !== '' ? ' ' . $providerMessage : '')));
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Asaas não está configurado para recebimentos.');
        }
    }
}
