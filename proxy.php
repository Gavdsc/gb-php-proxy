<?php

namespace GavsBlog;

/**
 * Class Proxy
 * Note: Proxy is only intended to be run once per request
 * Todo: expand for re-use using curl_reset().
 * Todo: Write a curl cookie cleanup script
 * Todo: Other request methods
 * Todo: Setup referrers (curl_setopt($this->ch, CURLOPT_REFERER, $address);
 * Todo: Header overrides
 * @package PathController
 */
class Proxy {
    private $ch; // @todo Check type is \CurlHandle
    private string $url;
    private string $cookie;
    private string $method;
    private array $responseHeaders;
    private string $requestHeaders;
    private string $response;
    private string $code = '500';
    private string $body;

    private array $rewrites;

    /**
     * Proxy constructor
     * @param string $url
     * @param false $echo
     * @param string $cookie
     * @param string|null $methodOverride
     * @param string|null $headerOverride
     * @param string|null $bodyOverride
     * @param array $rewrites
     */
    function __construct(string $url, bool $echo = false, string $cookie = '', string $methodOverride = null, string $headerOverride = null, string $bodyOverride = null, array $rewrites = []) {
        // Setup curl
        $this->ch = curl_init();

        // Set the rewrites
        $this->rewrites = $rewrites;

        // Set url assuming return transfer
        $this->setUrl($url);

        // Set method (either use the request method or override)
        $this->method = $methodOverride ? strtolower($methodOverride) : strtolower($_SERVER['REQUEST_METHOD']);

        // Set body (override body or get from input buffer)
        $this->body = $bodyOverride ?? file_get_contents('php://input') ?? '';

        // Setup cookie pot
        $this->cookie = $cookie;

        // Run the request
        $this->run();

        // Allow simple echo of response
        if ($echo) {
            // Set response headers (match to return)
            header('Content-Type: ' . $this->responseHeaders['content-type']);

            echo $this->response;
        }
    }

    /**
     * Proxy destructor
     * Cleanup curl when no longer needed, feel free to unset()
     */
    function __destruct() {
        curl_close($this->ch);
    }

    /**
     * Function: Return array of headers (request and response)
     * @return array
     */
    public function getHeaders(): array {
        return array(
            "method" => $this->method,
            "request" => $this->requestHeaders,
            "response" => $this->responseHeaders
        );
    }

    /**
     * Function: Return raw response string
     * @return string
     */
    public function getResponse(): string {
        return $this->response;
    }

    public function getCode(): string {
        return $this->code;
    }

    /**
     * Function: Setup and run curl
     */
    public function run() {
        // Process method
        $this->processMethod();

        // Set dir path for cookies
        if ($this->cookie !== '') {
            $this->makeDirPath($this->cookie);
            $this->setCookies();
        }

        // Catch response headers
        $headers = [];

        curl_setopt($this->ch, CURLOPT_HEADERFUNCTION,
            function($curl, $header) use (&$headers) {

                $length = strlen($header);
                $header = explode(':', $header, 2);

                if (count($header) < 2)
                    return $length;

                $headers[strtolower(trim($header[0]))][] = trim($header[1]);

                return $length;
            }
        );

        curl_setopt($this->ch, CURLINFO_HEADER_OUT, true);

        // Curl request
        $this->response = curl_exec($this->ch);

        // Save request and response headers
        $info = curl_getinfo($this->ch);

        // Check we have info before setting response headers
        // Todo: clean this up a bit
        if (!empty($info) && $info["http_code"] !== 0) {
            $this->code = $info['http_code'];

            $this->responseHeaders = $headers;

            // Todo: check against header size / for 404 before setting
            // Todo: convert string to array
            $this->requestHeaders = $info['request_header'];
        }
    }

    /**
     * Function: Set request url with/without headers
     * @param string $url
     * @param bool $transfer
     */
    private function setUrl(string $url, bool $transfer = true) {

        // Set the url
        $this->url = $this->routing($url);

        curl_setopt($this->ch, CURLOPT_URL, $this->url);

        if ($transfer) {
            curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        }
    }

    /**
     * Function: Basic router function for rewrites in * and $ - prefer htaccess for this, but in a pinch
     * @param $url
     * @return string
     */
    private function routing($url): string {

        // Check the url against rewrites
        foreach($this->rewrites as $key => $value) {

            // Check direct match
            if ($key == $url) {
                $url = $value;

                // Break loop on first match
                break;
            }

            $allCheck = explode('/', $key);
            $count = count($allCheck);

            // Check for all match
            // Todo: maybe expand for regex/preg_replace instead
            // Todo: replace with str_starts for PHP 8 at some stage
            if ($count > 0) {

                if ($allCheck[$count - 1] == '*') {

                    // Todo: Maybe catch query strings to pass

                    // Remove *
                    $replace = str_replace('/*', '', $key);

                    if (substr($url, 0, strlen($replace)) === $replace) {
                        $url = $value;

                        // Break loop on first match
                        break;
                    }
                }

                if ($allCheck[$count - 1] == '$') {
                    // Remove $
                    $replace = str_replace('/$', '', $key);

                    if (substr($url, 0, strlen($replace)) === $replace) {
                        // Replace the start of the url
                        $url = str_replace($replace, $value, $url);

                        // Break loop on first match
                        break;
                    }
                }

            }
        }

        return $url;
    }

    /**
     * Function: Switch statement for processing delivery methods
     */
    private function processMethod() {

        // @todo: Expand for other methods
        switch ($this->method) {
            case "post":
                $this->buildPost();
                break;
            case "options":
                $this->buildOptions();
                break;
            case "get":
            default:
                // Nothing extra needed
                break;
        }
    }

    /**
     * Function: Build post options / get current post data from input buffer
     */
    private function buildPost() {
        // Get post data from input buffer
        // $postData = file_get_contents('php://input');

        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? "application/octet-stream";

        // Set request content type for post (taken from incoming request / override in headerOverride if necessary)
        if ($contentType) {
            curl_setopt($this->ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: ' . $contentType
                )
            );
        }

        curl_setopt($this->ch, CURLOPT_POST, true);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, $this->body);
    }

    /**
     * Function: Build options request, mostly pre-flight stuff
     */
    private function buildOptions() {
        // @todo: Check and return CORS preflights
        curl_setopt($this->ch, CURLOPT_OPTIONS, true);
    }

    /**
     * Function: Override current request headers (passed on construction)
     */
    private function headerOverride() {
        // todo: header overrides
    }

    /**
     * Function: Setup cookie jar etc if cookies are enabled
     */
    private function setCookies() {
        // Setup php session to handle cookies per individual
        $sessionId = $this->handleSession();

        curl_setopt($this->ch, CURLOPT_COOKIEJAR, $this->cookie . "\\curl_$sessionId");
        curl_setopt($this->ch, CURLOPT_COOKIEFILE, $this->cookie . "\\curl_$sessionId");
    }

    /**
     * Function: Save sessions
     * @return false|string
     */
    private function handleSession(): bool|string {
        // Set session save path
        session_save_path($this->cookie);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Open new PHP session
            session_start();
        }

        return session_id();
    }

    /**
     * Function: Make file paths if necessary
     * @param string $path
     * @return bool
     */
    private function makeDirPath(string $path): bool {
        return file_exists($path) || mkdir($path, 0777, true);
    }
}