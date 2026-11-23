
<?php

class ApiException extends Exception
{
    public int $status;

    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($data);
    exit;
}

function jsonError(string $message, int $status = 400): void
{
    jsonResponse(["error" => $message], $status);
}

function requireMethod(string $method): void
{
    if ($_SERVER["REQUEST_METHOD"] !== strtoupper($method)) {
        jsonError("Method not allowed", 405);
    }
}

function requireUserApi(): int
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "user") {
        jsonError("Please log in first", 401);
    }

    return (int) $_SESSION["user_id"];
}

function readJson(): array
{
    $data = json_decode(file_get_contents("php://input"), true);
    return is_array($data) ? $data : [];
}

set_exception_handler(function (Throwable $error) {
    $status = $error instanceof ApiException ? $error->status : 500;

    if ($status === 500) {
        error_log($error->getMessage());
        jsonError("Internal server error", 500);
    }

    jsonError($error->getMessage(), $status);
});
