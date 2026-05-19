<?php
declare(strict_types=1);

namespace MaluDbEdge;

use PDO;

final readonly class AuthService
{
    public function __construct(private PDO $pdo) {}

    public function authenticate(?string $apiKey): ?AuthContext
    {
        if ($apiKey === null || $apiKey === '') {
            return null;
        }
        $fingerprint = ApiKey::fingerprint($apiKey);
        $stmt = $this->pdo->prepare(
            'SELECT k.id AS api_key_id, k.user_id, k.tenant_id, k.key_hash, k.role, k.scopes_json
               FROM api_keys k
               JOIN users u ON u.id = k.user_id
               JOIN tenants t ON t.id = k.tenant_id
              WHERE k.key_fingerprint = :fingerprint
                AND k.revoked_at IS NULL
                AND u.disabled_at IS NULL
                AND t.disabled_at IS NULL'
        );
        $stmt->execute([':fingerprint' => $fingerprint]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !ApiKey::verify($apiKey, (string)$row['key_hash'])) {
            return null;
        }
        $this->pdo->prepare('UPDATE api_keys SET last_used_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute([':id' => (int)$row['api_key_id']]);
        $scopes = json_decode((string)$row['scopes_json'], true);
        return new AuthContext(
            userId: (int)$row['user_id'],
            apiKeyId: (int)$row['api_key_id'],
            tenantId: (int)$row['tenant_id'],
            role: (string)$row['role'],
            scopes: is_array($scopes) ? array_values($scopes) : [],
        );
    }

    public function requireScope(AuthContext $context, string $scope): void
    {
        if (!$context->hasScope($scope)) {
            throw new \RuntimeException("Missing scope: {$scope}", 403);
        }
    }
}
