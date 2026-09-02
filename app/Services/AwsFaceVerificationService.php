<?php

namespace App\Services;

use Aws\Rekognition\RekognitionClient;
use Aws\Sts\StsClient;
use RuntimeException;

class AwsFaceVerificationService
{
    private function configured(): array
    {
        if (!class_exists(RekognitionClient::class) || !class_exists(StsClient::class)) {
            throw new RuntimeException('SDK da AWS não instalado. Execute composer update aws/aws-sdk-php.');
        }

        $region = trim((string) config('services.identity.aws_region', env('AWS_DEFAULT_REGION', 'us-east-1')));
        $key = trim((string) config('services.identity.aws_access_key_id', env('AWS_ACCESS_KEY_ID')));
        $secret = trim((string) config('services.identity.aws_secret_access_key', env('AWS_SECRET_ACCESS_KEY')));
        $roleArn = trim((string) config('services.identity.liveness_role_arn'));

        if ($region === '' || $key === '' || $secret === '' || $roleArn === '') {
            throw new RuntimeException('AWS Face Liveness ainda não está configurado.');
        }

        return [$region, $key, $secret, $roleArn];
    }

    private function credentials(string $key, string $secret): array
    {
        return ['key' => $key, 'secret' => $secret];
    }

    public function createSession(int $userId): array
    {
        [$region, $key, $secret, $roleArn] = $this->configured();
        $credentials = $this->credentials($key, $secret);

        $rekognition = new RekognitionClient([
            'version' => 'latest',
            'region' => $region,
            'credentials' => $credentials,
        ]);

        $created = $rekognition->createFaceLivenessSession([
            'ClientRequestToken' => bin2hex(random_bytes(16)),
            'Settings' => [
                'AuditImagesLimit' => 0,
                'ChallengePreferences' => [[
                    'Type' => 'FaceMovementChallenge',
                ]],
            ],
        ]);

        $sessionId = (string) ($created['SessionId'] ?? '');
        if ($sessionId === '') {
            throw new RuntimeException('A AWS não retornou uma sessão de prova de vida.');
        }

        $sts = new StsClient([
            'version' => 'latest',
            'region' => $region,
            'credentials' => $credentials,
        ]);

        $assumed = $sts->assumeRole([
            'RoleArn' => $roleArn,
            'RoleSessionName' => 'peter-liveness-' . $userId . '-' . time(),
            'DurationSeconds' => 900,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Allow',
                    'Action' => ['rekognition:StartFaceLivenessSession'],
                    'Resource' => '*',
                ]],
            ], JSON_UNESCAPED_SLASHES),
        ]);

        $temporary = $assumed['Credentials'] ?? null;
        if (!$temporary) {
            throw new RuntimeException('Não foi possível emitir credenciais temporárias para prova de vida.');
        }

        return [
            'session_id' => $sessionId,
            'region' => $region,
            'credentials' => [
                'accessKeyId' => (string) $temporary['AccessKeyId'],
                'secretAccessKey' => (string) $temporary['SecretAccessKey'],
                'sessionToken' => (string) $temporary['SessionToken'],
                'expiration' => method_exists($temporary['Expiration'] ?? null, 'format')
                    ? $temporary['Expiration']->format(DATE_ATOM)
                    : null,
            ],
        ];
    }

    public function verify(string $sessionId, string $documentImagePath): array
    {
        [$region, $key, $secret] = $this->configured();
        if (!is_file($documentImagePath) || !is_readable($documentImagePath)) {
            throw new RuntimeException('Documento de identidade não encontrado para comparação facial.');
        }

        $rekognition = new RekognitionClient([
            'version' => 'latest',
            'region' => $region,
            'credentials' => $this->credentials($key, $secret),
        ]);

        $result = $rekognition->getFaceLivenessSessionResults(['SessionId' => $sessionId]);
        $status = strtoupper((string) ($result['Status'] ?? ''));
        $confidence = (float) ($result['Confidence'] ?? 0);
        $referenceBytes = $result['ReferenceImage']['Bytes'] ?? null;

        if ($status !== 'SUCCEEDED' || !$referenceBytes) {
            return [
                'passed' => false,
                'liveness_status' => $status !== '' ? strtolower($status) : 'failed',
                'liveness_confidence' => $confidence,
                'face_similarity' => 0.0,
                'face_match_status' => 'failed',
            ];
        }

        $livenessThreshold = max(0, min((float) config('services.identity.liveness_threshold', 90), 100));
        $faceThreshold = max(0, min((float) config('services.identity.face_similarity_threshold', 92), 100));

        $comparison = $rekognition->compareFaces([
            'SourceImage' => ['Bytes' => file_get_contents($documentImagePath)],
            'TargetImage' => ['Bytes' => $referenceBytes],
            'SimilarityThreshold' => $faceThreshold,
            'QualityFilter' => 'AUTO',
        ]);

        $matches = $comparison['FaceMatches'] ?? [];
        $similarity = 0.0;
        foreach ($matches as $match) {
            $similarity = max($similarity, (float) ($match['Similarity'] ?? 0));
        }

        $passed = $confidence >= $livenessThreshold && $similarity >= $faceThreshold;

        return [
            'passed' => $passed,
            'liveness_status' => $confidence >= $livenessThreshold ? 'passed' : 'failed',
            'liveness_confidence' => round($confidence, 3),
            'face_similarity' => round($similarity, 3),
            'face_match_status' => $similarity >= $faceThreshold ? 'passed' : 'failed',
        ];
    }
}
