<?php
/**
 * Response Helper — Standardised HTTP Responses
 * AI Chatbot System — CBMS
 *
 * Usage:
 *   Response::success(['model' => $model]);
 *   Response::error('Not found', 404);
 *   Response::paginate($items, $total, $page, 20);
 */

namespace App\Helpers;

class Response
{
    // ----------------------------------------------------------------
    // Core JSON Output
    // ----------------------------------------------------------------

    /**
     * Send a JSON response and terminate execution.
     *
     * @param mixed $data    Any JSON-serialisable value
     * @param int   $status  HTTP status code
     */
    public static function json(mixed $data, int $status = 200): never
    {
        static::sendHeaders($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ----------------------------------------------------------------
    // Standard Response Envelopes
    // ----------------------------------------------------------------

    /**
     * Success response envelope.
     *
     * {
     *   "success": true,
     *   "message": "...",
     *   "data": { ... }
     * }
     */
    public static function success(mixed $data = null, string $message = '', int $status = 200): never
    {
        static::json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    /**
     * Error response envelope.
     *
     * {
     *   "success": false,
     *   "message": "...",
     *   "errors": { ... }
     * }
     */
    public static function error(string $message, int $status = 400, array $errors = []): never
    {
        $body = [
            'success' => false,
            'message' => $message,
        ];
        if (!empty($errors)) {
            $body['errors'] = $errors;
        }
        static::json($body, $status);
    }

    /**
     * Created response (201).
     */
    public static function created(mixed $data = null, string $message = 'Created successfully'): never
    {
        static::success($data, $message, 201);
    }

    /**
     * No-content response (204) — used for DELETE, etc.
     */
    public static function noContent(): never
    {
        http_response_code(204);
        exit;
    }

    // ----------------------------------------------------------------
    // Paginated Response
    // ----------------------------------------------------------------

    /**
     * Paginated list response.
     *
     * {
     *   "success": true,
     *   "data": [...],
     *   "pagination": {
     *     "total": 150,
     *     "per_page": 20,
     *     "current_page": 1,
     *     "last_page": 8,
     *     "from": 1,
     *     "to": 20,
     *     "has_next": true,
     *     "has_prev": false
     *   }
     * }
     */
    public static function paginate(
        array  $data,
        int    $total,
        int    $currentPage,
        int    $perPage = 20,
        string $message = ''
    ): never {
        $lastPage = (int) ceil($total / max(1, $perPage));
        $from     = ($currentPage - 1) * $perPage + 1;
        $to       = min($currentPage * $perPage, $total);

        static::json([
            'success'    => true,
            'message'    => $message,
            'data'       => $data,
            'pagination' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $currentPage,
                'last_page'    => $lastPage,
                'from'         => $total > 0 ? $from : 0,
                'to'           => $total > 0 ? $to   : 0,
                'has_next'     => $currentPage < $lastPage,
                'has_prev'     => $currentPage > 1,
            ],
        ]);
    }

    // ----------------------------------------------------------------
    // Redirect
    // ----------------------------------------------------------------

    /**
     * Redirect to another URL and exit.
     */
    public static function redirect(string $url, int $status = 302): never
    {
        $url = str_replace(["\r", "\n", "\0"], '', $url);
        http_response_code($status);
        header('Location: ' . $url);
        exit;
    }

    // ----------------------------------------------------------------
    // File Downloads
    // ----------------------------------------------------------------

    /**
     * Send a CSV file for download.
     */
    public static function csv(string $filename, array $headers, array $rows): never
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        // UTF-8 BOM for Excel Thai compatibility
        fputs($output, "\xEF\xBB\xBF");
        fputcsv($output, $headers);
        foreach ($rows as $row) {
            fputcsv($output, array_map([static::class, 'csvSanitizeCell'], $row));
        }
        fclose($output);
        exit;
    }

    /**
     * Neutralize spreadsheet formula injection: exported rows contain
     * user-typed chat text, and Excel executes any cell starting with
     * = + - @ (e.g. "=HYPERLINK(...)"). Prefixing a single quote makes
     * Excel treat it as literal text; numbers pass through untouched.
     * Public so pages that stream fputcsv directly can reuse it.
     */
    public static function csvSanitizeCell(mixed $cell): mixed
    {
        if (!is_string($cell) || $cell === '' || is_numeric($cell)) {
            return $cell;
        }
        if (in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $cell;
        }
        return $cell;
    }

    // ----------------------------------------------------------------
    // CORS Headers (for API endpoints)
    // ----------------------------------------------------------------

    /**
     * Set CORS headers (call before any output).
     * Validates the request Origin against CORS_ALLOWED_ORIGINS in .env.
     * If not configured, falls back to APP_URL only.
     */
    public static function setCorsHeaders(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed = static::getAllowedOrigins();

        if (in_array('*', $allowed, true)) {
            header('Access-Control-Allow-Origin: *');
        } elseif ($origin !== '' && in_array(rtrim($origin, '/'), $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        } else {
            header('Access-Control-Allow-Origin: ' . ($allowed[0] ?? 'null'));
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Max-Age: 86400');
    }

    /**
     * Handle preflight OPTIONS request for CORS.
     */
    public static function handlePreflight(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            static::setCorsHeaders();
            http_response_code(204);
            exit;
        }
    }

    private static function getAllowedOrigins(): array
    {
        $configured = $_ENV['CORS_ALLOWED_ORIGINS'] ?? '';
        if ($configured !== '') {
            return array_map(
                fn($o) => rtrim(trim($o), '/'),
                explode(',', $configured)
            );
        }
        $appUrl = $_ENV['APP_URL'] ?? '';
        if ($appUrl !== '') {
            return [rtrim($appUrl, '/')];
        }
        return [];
    }

    // ----------------------------------------------------------------
    // Input Helpers
    // ----------------------------------------------------------------

    /**
     * Get the raw JSON body as an array.
     */
    public static function getJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Validate that required fields exist in an array.
     * Returns list of missing field names, or empty array on success.
     */
    public static function validateRequired(array $data, array $required): array
    {
        $missing = [];
        foreach ($required as $field) {
            if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    // ----------------------------------------------------------------
    // Private Helpers
    // ----------------------------------------------------------------

    private static function sendHeaders(int $status): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
        }
    }
}
