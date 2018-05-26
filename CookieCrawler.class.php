<?php

class Crawler
{
    protected $_url;
    protected $_depth;
    protected $_host;
    protected $_useHttpAuth = false;
    protected $_user;
    protected $_pass;
    protected $_seen = array();
    protected $_filter = array();

    public function __construct($url, $depth = 5)
    {
        $this->_url = $url;
        $this->_depth = $depth;
        $parse = parse_url($url);
        $this->_host = $parse['host'];
    }

    protected function _processAnchors($content, $url, $depth)
    {
        $dom = new DOMDocument('1.0');
        @$dom->loadHTML($content);
        $anchors = $dom->getElementsByTagName('a');

        foreach ($anchors as $element) {
            $href = $element->getAttribute('href');
            if (0 !== strpos($href, 'http')) {
                $path = '/' . ltrim($href, '/');
                if (extension_loaded('http')) {
                    $href = http_build_url($url, array('path' => $path));
                } else {
                    $parts = parse_url($url);
                    $href = $parts['scheme'] . '://';
                    if (isset($parts['user']) && isset($parts['pass'])) {
                        $href .= $parts['user'] . ':' . $parts['pass'] . '@';
                    }
                    $href .= $parts['host'];
                    if (isset($parts['port'])) {
                        $href .= ':' . $parts['port'];
                    }
                    $href .= $path;
                }
            }
            // Crawl only link that belongs to the start domain
            $this->crawl_page($href, $depth - 1);
        }
    }

    protected function _getContent($url)
    {
        $handle = curl_init($url);
        if ($this->_useHttpAuth) {
            curl_setopt($handle, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($handle, CURLOPT_USERPWD, $this->_user . ":" . $this->_pass);
        }
        // follows 302 redirect, creates problem wiht authentication
//        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, TRUE);
        // return the content
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, TRUE);

        /* Get the HTML or whatever is linked in $url. */
        $response = curl_exec($handle);
        // response total time
        $time = curl_getinfo($handle, CURLINFO_TOTAL_TIME);
        /* Check for 404 (file not found). */
        $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);

        curl_close($handle);
        return array($response, $httpCode, $time);
    }

    protected function _printResult($url, $depth, $httpcode, $time)
    {
        ob_end_flush();
        $currentDepth = $this->_depth - $depth;
        $count = count($this->_seen);
        echo "N::$count,CODE::$httpcode,TIME::$time,DEPTH::$currentDepth URL::$url <br>";
        ob_start();
        flush();
    }

    protected function isValid($url, $depth)
    {
        if (strpos($url, $this->_host) === false
            || $depth === 0
            || isset($this->_seen[$url])
        ) {
            return false;
        }
        foreach ($this->_filter as $excludePath) {
            if (strpos($url, $excludePath) !== false) {
                return false;
            }
        }
        return true;
    }

    public function crawl_page($url, $depth)
    {
        if (!$this->isValid($url, $depth)) {
            return;
        }
        // add to the seen URL
        $this->_seen[$url] = true;
        // get Content and Return Code
        list($content, $httpcode, $time) = $this->_getContent($url);
        // process subPages
        $this->_processAnchors($content, $url, $depth);
    }

    public function setHttpAuth($user, $pass)
    {
        $this->_useHttpAuth = true;
        $this->_user = $user;
        $this->_pass = $pass;
    }

    public function addFilterPath($path)
    {
        $this->_filter[] = $path;
    }

    public function run()
    {
        $this->crawl_page($this->_url, $this->_depth);
		return $this->_seen;
    }
}

class CookieCrawler extends Crawler{
    
    public function getCookies($uri)
    {
        $ch = curl_init($uri);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        $result = curl_exec($ch);
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $result, $matches);        // get cookie
        $cookies = array();
        foreach($matches[1] as $item) {
            parse_str($item, $cookie);
            $cookies = array_merge($cookies, $cookie);
        }
        return $cookies;
    }

    public function run()
    {
        $this->crawl_page($this->_url, $this->_depth);
        $allCookies = [];
        foreach($this->_seen as $url => $status)
        {
            $cookies = $this->getCookies($url);
            foreach($cookies as $cookieName => $cookieValue)
            {
                $allCookies[$cookieName] = $cookieValue;
            }
        }
        return $allCookies;
    }
}

$startURL = 'https://stackoverflow.com/questions/2313107/how-do-i-make-a-simple-crawler-in-php';
$depth = 2;

$crawler = new CookieCrawler($startURL, $depth);

$allCookies = $crawler->run();

print_r($allCookies);

?>