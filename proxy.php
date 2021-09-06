<?php

namespace GavsBlog;

/**
 * Class Proxy
 * Note: Proxy is only intended to be run once per request / @todo expand for re-use using curl_reset().
 * @todo Write a curl cookie cleanup script
 * @todo Other request methods
 * @todo Redirect/route list
 * @todo Setupd referrers (curl_setopt($this->ch, CURLOPT_REFERER, $address);
 * @tood Header overrides
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

    /**
     * Proxy constructor
     * @param $url
     * @param false $echo
     * @param string $cookie
     * @param null $methodOverride
     * @param null $headerOverride
     */
    function __construct($url, $echo = false, $cookie = '', $methodOverride = null, $headerOverride = null) {
        // Setup curl
        $this->ch = curl_init();

        // Set url assuming return transfer
        $this->setUrl($url);

        // Set method (either use the request method or override)
        $this->method = $methodOverride ? strtolower($methodOverride) : strtolower($_SERVER['REQUEST_METHOD']);

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
     * @return Array
     */
    public function getHeaders() : array {
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
    public function getResponse() : string {
        return $this->response;
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
        $this->responseHeaders = $headers;

        // Todo: check against header size / for 404 before setting
        // Todo: convert string to array
        $this->requestHeaders = $info['request_header'];
    }

    /**
     * Function: Set request url with/without headers
     * @param $url
     * @param bool $transfer
     */
    private function setUrl($url, $transfer = true) {
        // Set the url
        $this->url = $url;

        curl_setopt($this->ch, CURLOPT_URL, $this->url);

        if ($transfer) {
            curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        }
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
        $postData = file_get_contents('php://input');

        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'];

        // Set request content type for post (taken from incoming request / override in headerOverride if necessary)
        if ($contentType) {
            curl_setopt($this->ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: ' . $contentType
                )
            );
        }

        curl_setopt($this->ch, CURLOPT_POST, true);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, $postData);
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
        // @todo: header overrides
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
    private function handleSession() {
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
     * @param $path
     * @return bool
     */
    private function makeDirPath($path) {
        return file_exists($path) || mkdir($path, 0777, true);
    }
}