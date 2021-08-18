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
    private $url;
    private $cookie;
    private string $response;

    function __construct($url, $echo = false, $cookie = '') {
        // Setup curl
        $this->ch = curl_init();

        // Set url assuming return transfer
        $this->setUrl($url);

        $this->cookie = $cookie;

        $this->run($echo);
    }

    // The method gets triggered as soon as there are no other references to a particular object. This can happen either when PHP decides to explicitly free the object, or when we force it using the unset()
    function __destruct() {
        curl_close($this->ch);
    }

    public function run($echo = false) {
        // Process method
        $this->processMethod();

        // Set dir path for cookies
        if ($this->cookie !== '') {
            $this->makeDirPath($this->cookie);
            $this->setCookies();
        }

        // Curl request
        $this->response = curl_exec($this->ch);

        if ($echo) {
            // Maybe you just need the response here? (get/set)
            echo $this->response;
        }
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
        // @todo can be expanded for other methods
        switch ($_SERVER['REQUEST_METHOD']) {
            case "POST":
                $this->processPost();
                break;
            default:
                break;
        }
    }

    private function processPost() {
        // Get post data from input buffer
        $postData = file_get_contents('php://input');

        curl_setopt($this->ch, CURLOPT_POST, true);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, $postData);
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

    public function response(): string {
        return $this->response;
    }

    private function test(bool $mandatory = false) {
        $mandatory = is_null($mandatory) ? false : $mandatory;
    }
}