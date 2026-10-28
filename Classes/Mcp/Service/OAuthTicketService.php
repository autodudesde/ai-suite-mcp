<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\HmacService;

class OAuthTicketService
{
    private const MFA_TICKET_TTL = 60;
    private const MFA_TICKET_PURPOSE = 'mcp-oauth-mfa';
    private const CONSENT_TICKET_TTL = 600;
    private const CONSENT_TICKET_PURPOSE = 'mcp-oauth-consent';

    public function __construct(
        private readonly HmacService $hmacService,
    ) {}

    public function createMfaTicket(int $beUserUid, string $ip): string
    {
        $expires = time() + self::MFA_TICKET_TTL;
        $payload = $beUserUid.'|'.$ip.'|'.$expires;
        $payloadEncoded = $this->base64UrlEncode($payload);
        $signature = $this->hmacService->hmac($payloadEncoded, self::MFA_TICKET_PURPOSE);

        return $payloadEncoded.'.'.$signature;
    }

    public function verifyMfaTicket(string $ticket, string $ip): ?int
    {
        $parts = explode('.', $ticket);
        if (2 !== count($parts)) {
            return null;
        }
        [$payloadEncoded, $signature] = $parts;

        $expected = $this->hmacService->hmac($payloadEncoded, self::MFA_TICKET_PURPOSE);
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $payload = $this->base64UrlDecode($payloadEncoded);
        if (null === $payload) {
            return null;
        }

        $segments = explode('|', $payload);
        if (3 !== count($segments)) {
            return null;
        }

        [$uid, $ticketIp, $expires] = $segments;
        if ($ticketIp !== $ip || (int) $expires < time()) {
            return null;
        }

        return (int) $uid;
    }

    public function createConsentTicket(
        int $beUserUid,
        string $clientId,
        string $redirectUri,
        string $codeChallenge,
        string $scope,
        string $resource,
        string $ip,
    ): string {
        $payload = [
            'uid' => $beUserUid,
            'cid' => $clientId,
            'ru' => $redirectUri,
            'cc' => $codeChallenge,
            'sc' => $scope,
            'res' => $resource,
            'ip' => $ip,
            'exp' => time() + self::CONSENT_TICKET_TTL,
        ];
        $payloadEncoded = $this->base64UrlEncode((string) json_encode($payload));
        $signature = $this->hmacService->hmac($payloadEncoded, self::CONSENT_TICKET_PURPOSE);

        return $payloadEncoded.'.'.$signature;
    }

    /**
     * @return null|array{uid: int, cid: string, ru: string, cc: string, sc: string, res: string, ip: string, exp: int}
     */
    public function verifyConsentTicket(string $ticket, string $ip): ?array
    {
        $parts = explode('.', $ticket);
        if (2 !== count($parts)) {
            return null;
        }
        [$payloadEncoded, $signature] = $parts;

        $expected = $this->hmacService->hmac($payloadEncoded, self::CONSENT_TICKET_PURPOSE);
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $json = $this->base64UrlDecode($payloadEncoded);
        if (null === $json) {
            return null;
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)
            || !isset($payload['uid'], $payload['cid'], $payload['ru'], $payload['cc'], $payload['res'], $payload['ip'], $payload['exp'])
        ) {
            return null;
        }

        if ((string) $payload['ip'] !== $ip || (int) $payload['exp'] < time()) {
            return null;
        }

        return [
            'uid' => (int) $payload['uid'],
            'cid' => (string) $payload['cid'],
            'ru' => (string) $payload['ru'],
            'cc' => (string) $payload['cc'],
            'sc' => (string) ($payload['sc'] ?? ''),
            'res' => (string) $payload['res'],
            'ip' => (string) $payload['ip'],
            'exp' => (int) $payload['exp'],
        ];
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): ?string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return false === $decoded ? null : $decoded;
    }
}
