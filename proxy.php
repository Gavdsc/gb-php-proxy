<?php

namespace GavsBlog;

/**
 * Class Proxy
 * Note: Proxy is only intended to be run once per request / @todo expand for re-use using curl_reset().
 * @todo Write a curl cookie cleanup script
 * @todo Other request methods
 * @todo Get/set
 * @todo Return head option
 * @package PathController
 */
class Proxy {
    private $ch;
    private string $url;
    private $cookie;
    private string $response;
    private array $responseHeaders;
    private array $requestHeaders;

    function __construct($url, $echo = false, $cookie = '') {
        // Setup curl
        $this->ch = curl_init();

        // Set url assuming return transfer
        $this->setUrl($url);

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
     * Function: Cleanup curl when no longer needed, feel free to unset()
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
     *
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

        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, 1);

        curl_setopt($this->ch, CURLOPT_HEADERFUNCTION,
            function($curl, $header) use (&$headers) {

                $length = strlen($header);
                $header = explode(':', $header, 2);

                if (count($header) < 2)
                    return $len;

                $headers[strtolower(trim($header[0]))][] = trim($header[1]);

                return $length;
            }
        );

        curl_setopt($this->ch, CURLINFO_HEADER_OUT, true);

        curl_setopt($this->ch, CURLOPT_REFERER, 'http://oculus:80');


        // Curl request
        $this->response = curl_exec($this->ch);

        // Save request and response headers
        $info = curl_getinfo($this->ch);

        $this->responseHeaders = $headers;
        $this->requestHeaders = $info['request_header'];

        //    var_dump($headers);

        //   var_dump($info['request_header']);


        // dump the headers
        /*   $header_size = curl_getinfo($this->ch, CURLINFO_HEADER_SIZE);
           $header = substr($this->response, 0, $header_size);

           var_dump($header); */
    }

    private function setUrl($url, $transfer = true) {
        // Set the url
        $this->url = $url;

        curl_setopt($this->ch, CURLOPT_URL, $this->url);

        if ($transfer) {
            curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        }
    }

    private function processMethod() {
        // @todo: Expand for other methods
        switch (strtolower($_SERVER['REQUEST_METHOD'])) {
            case "post":
                $this->buildPost();
                break;
            case "options":
                $this->buildOptions();
                break;
            case "get":
            default:
                break;
        }
    }

    private function buildPost() {
        // Get post data from input buffer
        $postData = file_get_contents('php://input');

        // Set json headers (for bi test) todo: intergrate properly
        curl_setopt($this->ch, CURLOPT_HTTPHEADER,
            array(
                'Content-Type: application/json',
                'Origin: http://oculus:80',
                'Access-Control-Request-Headers: content-type',
                'Access-Control-Request-Method: POST'
            )
        );

        curl_setopt($this->ch, CURLOPT_POST, true);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, $postData);
    }

    private function buildOptions() {
        // Check and return CORS preflights
        curl_setopt($this->ch, CURLOPT_OPTIONS, true);

        // Preflight CORS
        curl_setopt($this->ch, CURLOPT_HTTPHEADER,
            array(
                'Content-Type: application/json',
                'Origin: http://oculus:80',
                'Access-Control-Request-Headers: content-type',
                'Access-Control-Request-Method: POST'
            )
        );
    }

    private function setCookies() {
        // Setup php session to handle cookies per individual
        $sessionId = $this->handleSession();

        curl_setopt($this->ch, CURLOPT_COOKIEJAR, $this->cookie . "\\curl_$sessionId");
        curl_setopt($this->ch, CURLOPT_COOKIEFILE, $this->cookie . "\\curl_$sessionId");
    }

    private function handleSession() {
        // Set session save path
        session_save_path($this->cookie);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Open new PHP session
            session_start();
        }

        return session_id();
    }

    private function makeDirPath($path) {
        return file_exists($path) || mkdir($path, 0777, true);
    }

    private function test(bool $mandatory = false) {
        $mandatory = is_null($mandatory) ? false : $mandatory;
    }
}