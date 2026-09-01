<?php
/**
 * mail/graph-mailer.php
 *
 * Sends mail via the Microsoft Graph API (POST /users/{id}/sendMail) using
 * app-only (client credentials) OAuth2 -- no SMTP, no legacy basic auth, no
 * app password. Requires an Entra ID app registration with the Mail.Send
 * *application* permission, admin-consented, sending as a specific mailbox
 * (config.php's "sender").
 *
 * Replaces mail/smtp.php: that raw-socket SMTP client needed an app
 * password, which requires legacy per-user MFA -- unavailable on tenants
 * that use Conditional Access instead (see mail/config.sample.php). No
 * Composer/MSAL here either -- just curl and a client_credentials token
 * request, same "small and auditable" spirit as the mailer it replaces.
 */

class GraphMailerException extends Exception {}

class GraphMailer
{
    public function __construct(
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $senderUserId, // mailbox UPN/GUID to send as
        private readonly int $timeoutSeconds = 15
    ) {
        if (!function_exists('curl_init')) {
            throw new GraphMailerException('PHP curl extension is required.');
        }
    }

    /**
     * @param string $toEmail
     * @param string $subject
     * @param string $textBody
     * @param string|null $bccEmail
     * @param string|null $fromDisplayName Best-effort only -- Microsoft Graph
     *        may override this with the mailbox's actual configured display
     *        name. For a guaranteed result, set the display name on the
     *        mailbox itself in Exchange/Entra instead.
     * @throws GraphMailerException
     */
    public function send(string $toEmail, string $subject, string $textBody, ?string $bccEmail = null, ?string $fromDisplayName = null): void
    {
        $token = $this->getAccessToken();

        $message = [
            'subject' => $subject,
            'body' => ['contentType' => 'Text', 'content' => $textBody],
            'toRecipients' => [
                ['emailAddress' => ['address' => $toEmail]],
            ],
        ];
        if ($bccEmail !== null && $bccEmail !== '') {
            $message['bccRecipients'] = [
                ['emailAddress' => ['address' => $bccEmail]],
            ];
        }
        if ($fromDisplayName !== null && $fromDisplayName !== '') {
            $message['from'] = [
                'emailAddress' => ['address' => $this->senderUserId, 'name' => $fromDisplayName],
            ];
        }

        $encodedSender = rawurlencode($this->senderUserId);
        $url = "https://graph.microsoft.com/v1.0/users/{$encodedSender}/sendMail";
        $payload = (string) json_encode(['message' => $message, 'saveToSentItems' => true], JSON_UNESCAPED_SLASHES);

        [$status, $responseBody] = $this->httpPost($url, $payload, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);

        // sendMail returns 202 Accepted with no body on success.
        if ($status !== 202) {
            $data = json_decode($responseBody, true);
            $err = is_array($data) ? ($data['error']['message'] ?? $responseBody) : $responseBody;
            throw new GraphMailerException("sendMail failed ({$status}): {$err}");
        }
    }

    private function getAccessToken(): string
    {
        $tokenUrl = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token";
        $body = http_build_query([
            'client_id' => $this->clientId,
            'scope' => 'https://graph.microsoft.com/.default',
            'client_secret' => $this->clientSecret,
            'grant_type' => 'client_credentials',
        ]);

        [$status, $responseBody] = $this->httpPost($tokenUrl, $body, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        $data = json_decode($responseBody, true);
        if ($status !== 200 || !is_array($data) || !isset($data['access_token'])) {
            $err = is_array($data) ? ($data['error_description'] ?? $responseBody) : $responseBody;
            throw new GraphMailerException("Token request failed ({$status}): {$err}");
        }
        return (string) $data['access_token'];
    }

    /** @return array{0:int,1:string} [http status, response body] */
    private function httpPost(string $url, string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new GraphMailerException("HTTP request failed: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, (string) $responseBody];
    }
}
