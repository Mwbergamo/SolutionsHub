<?php
/**
 * mail/smtp.php
 *
 * A minimal, dependency-free SMTP-over-sockets client. No Composer, no
 * PHPMailer/Swiftmailer — just stream_socket_client() and hand-rolled
 * SMTP commands, good enough for STARTTLS + AUTH LOGIN against Gmail /
 * Google Workspace or Microsoft 365 on port 587. Not a general-purpose
 * mail library — it does exactly what send-quote.php needs and nothing
 * more, so it stays small and auditable.
 */

class SimpleSmtpException extends Exception {}

class SimpleSmtpMailer
{
    /** @var resource|null */
    private $sock;
    private $debugLog = [];

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption, // 'tls' (STARTTLS) or '' (none)
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeoutSeconds = 15
    ) {}

    /**
     * @param string $fromEmail
     * @param string $fromName
     * @param string $toEmail
     * @param string $subject
     * @param string $textBody
     * @param string|null $bccEmail
     * @throws SimpleSmtpException
     */
    public function send(string $fromEmail, string $fromName, string $toEmail, string $subject, string $textBody, ?string $bccEmail = null): void
    {
        $this->connect();
        try {
            $this->ehlo();
            if ($this->encryption === 'tls') {
                $this->command("STARTTLS", 220);
                if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new SimpleSmtpException('STARTTLS negotiation failed');
                }
                $this->ehlo(); // must re-EHLO after STARTTLS
            }
            $this->authLogin();

            $this->command("MAIL FROM:<{$fromEmail}>", 250);
            $this->command("RCPT TO:<{$toEmail}>", 250, [251, 250]);
            if ($bccEmail) {
                $this->command("RCPT TO:<{$bccEmail}>", 250, [251, 250]);
            }
            $this->command("DATA", 354);

            $headers = $this->buildHeaders($fromEmail, $fromName, $toEmail, $subject);
            $body = $this->dotStuff($textBody);
            $this->write($headers . "\r\n" . $body . "\r\n.\r\n");
            $this->readResponse(250);

            $this->command("QUIT", 221);
        } finally {
            $this->close();
        }
    }

    private function buildHeaders(string $fromEmail, string $fromName, string $toEmail, string $subject): string
    {
        $date = date('r');
        $messageId = '<' . bin2hex(random_bytes(12)) . '@solutionshub>';
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $lines = [
            "Date: {$date}",
            "Message-ID: {$messageId}",
            "From: {$encodedFromName} <{$fromEmail}>",
            "To: <{$toEmail}>",
            "Subject: {$encodedSubject}",
            "MIME-Version: 1.0",
            "Content-Type: text/plain; charset=UTF-8",
            "Content-Transfer-Encoding: 8bit",
        ];
        return implode("\r\n", $lines) . "\r\n";
    }

    /** RFC 5321 "dot-stuffing": lines starting with '.' get an extra '.' prepended. */
    private function dotStuff(string $body): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = explode("\n", $normalized);
        foreach ($lines as $i => $line) {
            if (isset($line[0]) && $line[0] === '.') {
                $lines[$i] = '.' . $line;
            }
        }
        return implode("\r\n", $lines);
    }

    private function connect(): void
    {
        $errno = 0;
        $errstr = '';
        $remote = "tcp://{$this->host}:{$this->port}";
        $this->sock = @stream_socket_client($remote, $errno, $errstr, $this->timeoutSeconds);
        if (!$this->sock) {
            throw new SimpleSmtpException("Could not connect to {$this->host}:{$this->port} ({$errstr})");
        }
        stream_set_timeout($this->sock, $this->timeoutSeconds);
        $this->readResponse(220);
    }

    private function ehlo(): void
    {
        $localName = gethostname() ?: 'localhost';
        $this->command("EHLO {$localName}", 250);
    }

    private function authLogin(): void
    {
        $this->command("AUTH LOGIN", 334);
        $this->command(base64_encode($this->username), 334);
        $this->command(base64_encode($this->password), 235);
    }

    private function command(string $line, int $expectCode, array $alsoOk = []): string
    {
        $this->write($line . "\r\n");
        return $this->readResponse($expectCode, $alsoOk);
    }

    private function write(string $data): void
    {
        if (!$this->sock) throw new SimpleSmtpException('Not connected');
        $bytes = fwrite($this->sock, $data);
        if ($bytes === false) throw new SimpleSmtpException('Write to SMTP socket failed');
    }

    private function readResponse(int $expectCode, array $alsoOk = []): string
    {
        if (!$this->sock) throw new SimpleSmtpException('Not connected');
        $full = '';
        while (!feof($this->sock)) {
            $line = fgets($this->sock, 515);
            if ($line === false) break;
            $full .= $line;
            $this->debugLog[] = trim($line);
            // Multi-line responses use "250-..." until the final "250 ...".
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        $code = (int) substr($full, 0, 3);
        $okCodes = array_merge([$expectCode], $alsoOk);
        if (!in_array($code, $okCodes, true)) {
            throw new SimpleSmtpException("SMTP error: expected {$expectCode}, got: " . trim($full));
        }
        return $full;
    }

    private function close(): void
    {
        if ($this->sock) {
            fclose($this->sock);
            $this->sock = null;
        }
    }
}
