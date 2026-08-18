<?php

namespace App\Services;

use App\Helpers\Database;

class EmailService
{
    private Database $db;
    private array $config;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = $this->loadConfig();
    }

    private function loadConfig(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'mail_%'"
        );
        $cfg = [];
        foreach ($rows as $r) {
            $cfg[$r['setting_key']] = $r['setting_value'];
        }
        return $cfg;
    }

    public function isEnabled(): bool
    {
        return ($this->config['mail_enabled'] ?? 'false') === 'true'
            && $this->hasSmtpConfig();
    }

    public function hasSmtpConfig(): bool
    {
        return !empty($this->config['mail_host'])
            && !empty($this->config['mail_username'])
            && !empty($this->config['mail_password']);
    }

    public function getMissingConfig(): array
    {
        $missing = [];
        if (empty($this->config['mail_host'])) $missing[] = 'SMTP Host';
        if (empty($this->config['mail_username'])) $missing[] = 'Username';
        if (empty($this->config['mail_password'])) $missing[] = 'Password';
        return $missing;
    }

    public function sendHandoffNotification(int $botId, array $handoffData): bool
    {
        if (!$this->hasSmtpConfig()) return false;

        $recipient = $this->getBotHandoffEmail($botId);
        if (!$recipient) return false;

        $botName = $this->db->fetchColumn('SELECT name FROM bots WHERE id = ?', [$botId]) ?: 'Bot #' . $botId;

        $template = $this->config['mail_handoff_template'] ?? $this->defaultHandoffTemplate();
        $subject  = $this->config['mail_handoff_subject'] ?? '[CBMS] คำขอคุยกับเจ้าหน้าที่ - {bot_name}';

        // contact_id and message arrive straight from the PUBLIC handoff API —
        // escape them, or anyone can plant live HTML (phishing links) in the
        // notification mail admins receive. Strip CR/LF too so user text can
        // never break out of the SMTP DATA section.
        $strip = fn(?string $v): string => str_replace(["\r", "\n"], ' ', (string)$v);
        $html  = fn(?string $v): string => htmlspecialchars($strip($v), ENT_QUOTES, 'UTF-8');

        $vars = [
            '{bot_name}'     => $strip($botName),
            '{contact_type}' => $this->contactTypeLabel($handoffData['contact_type']),
            '{contact_id}'   => $strip($handoffData['contact_id']),
            '{message}'      => $strip($handoffData['user_message'] ?? '-'),
            '{datetime}'     => date('d/m/Y H:i:s'),
            '{admin_url}'    => ($_ENV['APP_URL'] ?? '') . '/admin/handoff.php',
        ];
        $varsHtml = array_map($html, [
            '{bot_name}'     => $botName,
            '{contact_type}' => $this->contactTypeLabel($handoffData['contact_type']),
            '{contact_id}'   => $handoffData['contact_id'],
            '{message}'      => $handoffData['user_message'] ?? '-',
        ]) + $vars;

        // Subject is base64-encoded later (no HTML context) — plain vars.
        $subject = strtr($subject, $vars);
        $body    = strtr($template, $varsHtml);

        return $this->send($recipient, $subject, $body);
    }

    public function sendTestEmail(string $to): bool
    {
        $subject = '[CBMS] ทดสอบอีเมลแจ้งเตือน';
        $body = $this->buildHtml(
            'ทดสอบอีเมลสำเร็จ',
            '<p style="font-size:16px;color:#334155;">ระบบแจ้งเตือนทางอีเมลทำงานปกติ</p>'
            . '<p style="color:#64748b;font-size:13px;">ส่งเมื่อ: ' . date('d/m/Y H:i:s') . '</p>'
        );
        return $this->send($to, $subject, $body);
    }

    public function sendTestHandoff(string $to): bool
    {
        $template = $this->config['mail_handoff_template'] ?? $this->defaultHandoffTemplate();
        $subject  = $this->config['mail_handoff_subject'] ?? '[CBMS] คำขอคุยกับเจ้าหน้าที่ - {bot_name}';

        $vars = [
            '{bot_name}'     => 'ตัวอย่างบอท (ทดสอบ)',
            '{contact_type}' => 'LINE',
            '{contact_id}'   => 'test_user_01',
            '{message}'      => 'นี่คือข้อความทดสอบจากระบบ',
            '{datetime}'     => date('d/m/Y H:i:s'),
            '{admin_url}'    => ($_ENV['APP_URL'] ?? '') . '/admin/handoff.php',
        ];

        return $this->send($to, strtr($subject, $vars), strtr($template, $vars));
    }

    public function send(string $to, string $subject, string $htmlBody): bool
    {
        $host     = $this->config['mail_host'] ?? '';
        $port     = (int)($this->config['mail_port'] ?? 587);
        $username = $this->config['mail_username'] ?? '';
        $password = $this->config['mail_password'] ?? '';
        $from     = $this->config['mail_from_address'] ?? $username;
        $fromName = $this->config['mail_from_name'] ?? 'CBMS Notification';
        $encryption = $this->config['mail_encryption'] ?? 'tls';

        if (!$host || !$username) return false;

        try {
            $socket = $this->smtpConnect($host, $port, $encryption);
            if (!$socket) return false;

            $this->smtpCommand($socket, "EHLO " . gethostname());

            if ($encryption === 'tls' && $port !== 465) {
                $this->smtpCommand($socket, "STARTTLS");
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                $this->smtpCommand($socket, "EHLO " . gethostname());
            }

            $this->smtpCommand($socket, "AUTH LOGIN");
            $this->smtpCommand($socket, base64_encode($username));
            $this->smtpCommand($socket, base64_encode($password));

            $this->smtpCommand($socket, "MAIL FROM:<{$from}>");

            $recipients = array_map('trim', explode(',', $to));
            foreach ($recipients as $rcpt) {
                if ($rcpt) $this->smtpCommand($socket, "RCPT TO:<{$rcpt}>");
            }

            $this->smtpCommand($socket, "DATA");

            $headers  = "From: {$fromName} <{$from}>\r\n";
            $headers .= "To: {$to}\r\n";
            $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "Date: " . date('r') . "\r\n";
            $headers .= "\r\n";

            $message = $headers . $htmlBody . "\r\n.";
            $this->smtpCommand($socket, $message);

            $this->smtpCommand($socket, "QUIT");
            fclose($socket);
            return true;
        } catch (\Throwable $e) {
            if (isset($socket) && is_resource($socket)) fclose($socket);
            error_log("EmailService error: " . $e->getMessage());
            return false;
        }
    }

    private function smtpConnect(string $host, int $port, string $encryption)
    {
        $address = ($encryption === 'ssl' || $port === 465) ? "ssl://{$host}" : "tcp://{$host}";
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $socket = @stream_socket_client("{$address}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$socket) {
            throw new \RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($socket, 15);
        $this->smtpRead($socket);
        return $socket;
    }

    private function smtpCommand($socket, string $cmd): string
    {
        fwrite($socket, $cmd . "\r\n");
        return $this->smtpRead($socket);
    }

    private function smtpRead($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        $code = (int)substr($response, 0, 3);
        if ($code >= 400) {
            throw new \RuntimeException("SMTP error {$code}: " . trim($response));
        }
        return $response;
    }

    private function getBotHandoffEmail(int $botId): ?string
    {
        $email = $this->db->fetchColumn(
            "SELECT setting_value FROM bot_settings WHERE bot_id = ? AND setting_key = 'handoff_email'",
            [$botId]
        );
        return $email ?: null;
    }

    private function contactTypeLabel(string $type): string
    {
        return match ($type) {
            'line'     => 'LINE',
            'facebook' => 'Facebook',
            'phone'    => 'โทรศัพท์',
            default    => $type,
        };
    }

    private function defaultHandoffTemplate(): string
    {
        return $this->buildHtml('คำขอคุยกับเจ้าหน้าที่', '
            <table style="width:100%;border-collapse:collapse;">
                <tr><td style="padding:8px 0;color:#64748b;width:120px;">บอท</td><td style="padding:8px 0;font-weight:600;color:#1e293b;">{bot_name}</td></tr>
                <tr><td style="padding:8px 0;color:#64748b;">ช่องทาง</td><td style="padding:8px 0;font-weight:600;color:#1e293b;">{contact_type}</td></tr>
                <tr><td style="padding:8px 0;color:#64748b;">Contact</td><td style="padding:8px 0;font-weight:600;color:#1e293b;">{contact_id}</td></tr>
                <tr><td style="padding:8px 0;color:#64748b;">ข้อความ</td><td style="padding:8px 0;color:#1e293b;">{message}</td></tr>
                <tr><td style="padding:8px 0;color:#64748b;">เวลา</td><td style="padding:8px 0;color:#1e293b;">{datetime}</td></tr>
            </table>
            <div style="margin-top:24px;">
                <a href="{admin_url}" style="display:inline-block;padding:10px 24px;background:#6366f1;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">ดูใน Admin Panel</a>
            </div>
        ');
    }

    public function buildHtml(string $title, string $content): string
    {
        $appName = $_ENV['APP_NAME'] ?? 'CBMS';
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f1f5f9;font-family:sans-serif;">
        <div style="max-width:560px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1);">
            <div style="background:linear-gradient(135deg,#6366f1,#818cf8);padding:24px;text-align:center;">
                <h1 style="color:#fff;margin:0;font-size:18px;">' . htmlspecialchars($title) . '</h1>
            </div>
            <div style="padding:24px;">' . $content . '</div>
            <div style="padding:16px 24px;background:#f8fafc;text-align:center;color:#94a3b8;font-size:12px;">
                ' . htmlspecialchars($appName) . ' — Email Notification
            </div>
        </div></body></html>';
    }
}
