<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Http;

use InvalidArgumentException;
use JsonException;
use Jzvikas\OnePageCheckout\Checkout\CheckoutError;

final readonly class CheckoutJsonResponse
{
    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     */
    public function __construct(
        public int $statusCode,
        public array $body,
        public array $headers = [],
    ) {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new InvalidArgumentException('HTTP status code must be between 100 and 599.');
        }

        foreach ($headers as $name => $value) {
            if (!is_string($name) || trim($name) === '' || !is_string($value) || str_contains($name, "\r") || str_contains($name, "\n") || str_contains($value, "\r") || str_contains($value, "\n")) {
                throw new InvalidArgumentException('Checkout response headers must be non-empty single-line strings.');
            }
        }
    }

    public static function error(
        int $statusCode,
        string $code,
        string $message,
        ?string $stateVersion = null,
        bool $retryable = false,
        array $headers = [],
    ): self {
        $error = new CheckoutError($code, $message);

        return new self(
            $statusCode,
            [
                'success' => false,
                'stateVersion' => $stateVersion,
                'sections' => [],
                'errors' => [$error->toArray()],
                'redirect' => null,
                'retryable' => $retryable,
            ],
            $headers,
        );
    }

    /** @throws JsonException */
    public function toJson(): string
    {
        $body = $this->body;

        // `sections` is a JSON object/map contract even when no DOM refresh is required. PHP's
        // empty array would otherwise serialize as `[]`, which the browser correctly rejects as a
        // malformed mutation response. Keep the internal PHP representation as array<string,string>
        // and normalize only at the transport boundary so errors remains a JSON list.
        if (array_key_exists('sections', $body) && is_array($body['sections'])) {
            $body['sections'] = (object) $body['sections'];
        }

        return json_encode(
            $body,
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
}
