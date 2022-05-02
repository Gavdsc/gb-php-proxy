<?php

namespace GavsBlog;

/**
 * Class Proxy
 * Note: Proxy is only intended to be run once per request
 * Todo: expand for re-use using curl_reset().
 * Todo: Other request methods
 * Todo: Setup referrers (curl_setopt($this->ch, CURLOPT_REFERER, $address);
 * Todo: Header overrides
 * @package PathController
 */
class Proxy {
    private $ch; // @todo Check type is \CurlHandle
    private string $cookie = '';
    private string $method = 'GET';
    private array $responseHeaders;
    private string $requestHeaders;
    private string $response;
    private string $code = '500';
    private string $body;
    private bool $call = false;

    // Array to hold routes
    private array $routes = [];

    /**
     * Proxy constructor
     */
    function __construct() {
        // Setup curl
        $this->ch = curl_init();

        //headers for echo
        //

    }

    /**
     * Static helper function: for proxying a get request
     * @param string $target
     * @param string|null $body
     * @return Proxy
     */
    public static function get(string $target, string $body = null): Proxy {

        $proxy = new Proxy();

        $proxy->route("*", $target, "GET", $body);

        $proxy->run();

        return $proxy;
    }

    /**
     * Static helper function: for proxying a post request
     * Todo: headers so we can specify type, e.g. JSON
     * @param string $target
     * @param string|null $body
     * @return Proxy
     */
    public static function post(string $target, string $body = null): Proxy {

        $proxy = new Proxy();

        $proxy->route("*", $target, "POST", $body);

        $proxy->run();

        return $proxy;
    }

    /**
     * Static helper function: for proxying a url forward
     * @param string $target
     * @return Proxy
     */
    public static function forward(string $target): Proxy {

        $proxy = new Proxy();

        $proxy->route("/$", $target);

        $proxy->run($_SERVER['REQUEST_URI']);

        return $proxy;
    }

    /**
     * Static helper function: for using the proxy as a router
     * @return Proxy
     */
    public static function router(): Proxy {
        return new Proxy();
    }

    /**
     * Function: Adds a route to the proxy. It assumes forward (body/method) if not specified otherwise
     * Todo: add query string option to route
     * @param $rule
     * @param $target
     * @param null $method
     * @param null $body
     */
    public function route($rule, $target, $method = null, $body = null) {
        // Check if rule exists and don't add if it does.

        $this->routes[$rule] = [
            "target" => $target,
            "method" => $method ?? $_SERVER['REQUEST_METHOD'],
            "body" => $body ?? file_get_contents('php://input') ?? ""
        ];
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
        if (!$this->call) return 'No response, the call was unsuccessful or not made';

        return $this->response;
    }

    public function getCode(): string {
        if (!$this->call) return 'No code, the call was unsuccessful or not made';

        return $this->code;
    }

    public function checkCall(): bool {
        return $this->call;
    }

    /**
     * Function: print the response if a call has been made
     * @return bool
     */
    public function echoResponse(): bool {
        if (!$this->call) return false;

        //    header('Content-Type: ' . $this->responseHeaders['content-type']);

        echo $this->getResponse();

        return true;
    }

    /**
     * Function: Setup and run curl
     */
    public function run($url = "") {
        // Bail out if can't set a url / there are no router matches
        if (!$this->setUrl($url)) return;

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

        $this->call = true;

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
     * @param string $current
     * @param bool $transfer
     * @return bool
     */
    private function setUrl(string $current, bool $transfer = true): bool {

        // Set the url
        $url = $this->routing($current);

        if ($url == '') return false;

        curl_setopt($this->ch, CURLOPT_URL, $url);

        if ($transfer) {
            curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        }

        return true;
    }

    /**
     * Function: Basic router function for rewrites in * and $ - prefer htaccess for this, but in a pinch
     * Todo: rewrite in regex / cleanup duplicated (works for now)
     * @param string $current
     * @return string
     */
    private function routing(string $current): string {

        $url = '';

        // Loop through urls and check for match. Set url if match.
        foreach ($this->routes as $key => $value) {

            // Check direct match
            if ($key == $current) {
                // Todo: replace with a set
                $url = $value["target"];
                $this->method = $value["method"];
                $this->body = $value["body"];

                // Break loop on first match
                break;
            }

            // Check for all match
            // Todo: maybe expand for regex/preg_replace instead
            // Todo: replace with str_starts for PHP 8 at some stage
            $allCheck = explode('/', $key);
            $count = count($allCheck);

            if ($count > 0) {

                if ($allCheck[$count - 1] == '*') {

                    // Remove *
                    $replace = str_replace('*', '', $key);

                    if (substr($current, 0, strlen($replace)) === $replace) {
                        $url = $value["target"];
                        $this->method = $value["method"];
                        $this->body = $value["body"];

                        // Break loop on first match
                        break;
                    }
                }

                if ($allCheck[$count - 1] == '$') {
                    // Remove $
                    $replace = str_replace('/$', '', $key);

                    if ($replace == '') {
                        $url = $value["target"] . $current;
                        $this->method = $value["method"];
                        $this->body = $value["body"];

                        break;
                    }

                    if (substr($current, 0, strlen($replace)) === $replace) {
                        // Replace the start of the url
                        $url = str_replace($replace, $value["target"], $current);
                        $this->method = $value["method"];
                        $this->body = $value["body"];

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