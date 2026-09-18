<?php
/**
 * Error handling: detailed errors in development, generic messages in production.
 * Real errors are always written to storage/logs/app-YYYY-MM-DD.log.
 */

function is_dev(): bool
{
    return config('app.env') !== 'production';
}

function app_log(string $level, string $message, array $context = []): void
{
    $dir = config('app.log_path');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = sprintf(
        "[%s] %s: %s %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        $context ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''
    );
    @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
}

function register_error_handlers(): void
{
    error_reporting(E_ALL);
    ini_set('display_errors', is_dev() ? '1' : '0');
    ini_set('log_errors', '1');

    set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    set_exception_handler(function (Throwable $e): void {
        app_log('error', $e->getMessage(), [
            'type' => get_class($e),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'uri'  => $_SERVER['REQUEST_URI'] ?? '',
        ]);

        if (!headers_sent()) {
            http_response_code(500);
        }

        if (is_api_request()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'success' => false,
                'message' => is_dev() ? $e->getMessage() : 'Something went wrong. Please try again or call us.',
                'data'    => new stdClass(),
            ]);
            exit;
        }

        if (is_dev()) {
            echo '<pre style="padding:20px;background:#1e1b2e;color:#fca5a5;white-space:pre-wrap">'
                . htmlspecialchars(get_class($e) . ': ' . $e->getMessage() . "\n\n" . $e->getTraceAsString(), ENT_QUOTES, 'UTF-8')
                . '</pre>';
        } else {
            readfile(ROOT_PATH . '/includes/partials/error-500.html');
        }
        exit;
    });
}

function is_api_request(): bool
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return str_contains($uri, '/api/')
        || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}
