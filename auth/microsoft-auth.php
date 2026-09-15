<?php
declare(strict_types=1);

/**
 * auth/microsoft-auth.php
 *
 * Delegated ("sign in as yourself") OAuth2 Authorization Code flow against
 * Entra ID -- a SEPARATE app registration from mail/graph-mailer.php's
 * app-only Mail.Send credential (different permission model entirely:
 * this one signs a real person in and reads their own profile via
 * delegated User.Read; that one sends mail as a shared mailbox with no
 * user present). Same "small and auditable" spirit as GraphMailer -- just
 * curl, no Composer/MSAL.
 */

class MicrosoftAuthException extends Exception {}

class MicrosoftAuth
{
    public function __construct(
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly int $timeoutSeconds = 15
    ) {
        if (!function_exists('curl_init')) {
            throw new MicrosoftAuthException('PHP curl extension is required.');
        }
    }

    /** Builds the URL to send the browser to for Microsoft sign-in. */
    public function authorizeUrl(string $state): string
    {
        $params = http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'response_mode' => 'query',
            'scope' => 'openid profile email User.Read',
            'state' => $state,
        ]);
        return "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/authorize?{$params}";
    }

    /** Exchanges an authorization code (from the callback's ?code=) for an access token. */
    public function exchangeCodeForAccessToken(string $code): string
    {
        $tokenUrl = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token";
        $body = http_build_query([
            'client_id' => $this->clientId,
            'scope' => 'openid profile email User.Read',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
            'client_secret' => $this->clientSecret,
        ]);

        [$status, $responseBody] = $this->httpPost($tokenUrl, $body, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        $data = json_decode($responseBody, true);
        if ($status !== 200 || !is_array($data) || !isset($data['access_token'])) {
            $err = is_array($data) ? ($data['error_description'] ?? $responseBody) : $responseBody;
            throw new MicrosoftAuthException("Token request failed ({$status}): {$err}");
        }
        return (string) $data['access_token'];
    }

    /**
     * Calls Graph /me with the access token -- this is both how we get the
     * signed-in person's profile AND how we confirm the token is real:
     * Graph rejects an invalid/expired token outright, so there's no
     * separate need to verify the ID token's JWT signature ourselves. This
     * access token only ever came from a direct server-to-server call we
     * just made to Microsoft (exchangeCodeForAccessToken() above), never
     * from anything handed to us by the browser, so it's already trusted.
     *
     * @return array{oid: string, name: string, email: string}
     */
    public function fetchProfile(string $accessToken): array
    {
        $ch = curl_init('https://graph.microsoft.com/v1.0/me');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new MicrosoftAuthException("Graph /me request failed: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string) $responseBody, true);
        if ($status !== 200 || !is_array($data)) {
            $err = is_array($data) ? ($data['error']['message'] ?? $responseBody) : $responseBody;
            throw new MicrosoftAuthException("Graph /me failed ({$status}): {$err}");
        }

        // Some mailboxes report 'mail' as null even when fully licensed
        // (e.g. account created before a mailbox was provisioned) --
        // userPrincipalName is always present and is the sign-in identity
        // either way.
        $email = $data['mail'] ?? $data['userPrincipalName'] ?? null;
        $oid = $data['id'] ?? null;
        $name = $data['displayName'] ?? null;
        if (!is_string($email) || $email === '' || !is_string($oid) || $oid === '' || !is_string($name) || $name === '') {
            throw new MicrosoftAuthException('Graph /me returned an incomplete profile.');
        }

        return ['oid' => $oid, 'name' => $name, 'email' => strtolower($email)];
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
            throw new MicrosoftAuthException("HTTP request failed: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, (string) $responseBody];
    }
}
