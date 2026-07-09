<?php

declare(strict_types=1);

namespace Mata\Core;

use Mata\Config\Dto\ApplicationConfig;
use Mata\Services\RateLimiter;
use Mata\Utils\PathResolver;

/**
 * Main application container with authentication and rate limiting.
 */
class App
{
    public Auth $auth;
    public ApplicationConfig $config;
    public PathResolver $paths;
    public ?RateLimiter $rateLimiter = null;
    public string $baseUrl = '';
    private string $basePath = '';

    /**
     * Initialize application with authentication and path management.
     */
    public function __construct(
        Auth $auth,
        ApplicationConfig $config,
        PathResolver $paths,
        ?RateLimiter $rateLimiter = null
    ) {
        $this->auth = $auth;
        $this->config = $config;
        $this->paths = $paths;
        $this->rateLimiter = $rateLimiter;
        $this->detectBasePath();
    }

    /**
     * Auto-detect base URL path from HTTP request.
     *
     * Handles different deployment scenarios:
     * - Production: mata.mydomain.com/ → SCRIPT_NAME: /index.php → basePath: ''
     * - Development: localhost:8001/public/ → SCRIPT_NAME: /public/index.php → basePath: '/public'
     */
    private function detectBasePath(): void
    {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $scriptDir = dirname($scriptName);
        // If we're in root directory, no base path needed
        $this->basePath = ($scriptDir === '/') ? '' : $scriptDir;
    }

    /**
     * Auto-detect protocol from current request.
     *
     * @return string 'http' or 'https'
     */
    private function detectProtocol(): string
    {
        // Check if HTTPS is enabled
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return 'https';
        }

        // Check REQUEST_SCHEME (more reliable than HTTPS header)
        if (isset($_SERVER['REQUEST_SCHEME'])) {
            return $_SERVER['REQUEST_SCHEME'];
        }

        // Check if forwarded from proxy (common in Docker/cloud setups)
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            return $_SERVER['HTTP_X_FORWARDED_PROTO'];
        }

        // Default to HTTP
        return 'http';
    }

    /**
     * Set base URL for the application.
     *
     * @param string $url Base URL including protocol and domain
     */
    public function setBaseUrl(string $url): void
    {
        $this->baseUrl = $url;
    }

    /**
     * Get configured base URL for the application.
     *
     * @return string Base URL or empty string if not set
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Get the detected base path for URL construction.
     *
     * @return string Base path (e.g., '/public' or '')
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * Get full base URL with correct protocol and path.
     *
     * @return string Full URL like 'http://localhost:8001/public' or 'https://mata.mydomain.com'
     */
    public function getFullBaseUrl(): string
    {
        $protocol = $this->detectProtocol();
        $host = $_SERVER['HTTP_HOST'] ?? $this->baseUrl;

        return $protocol . '://' . $host . $this->basePath;
    }
}
