<?php

/*
Plugin Name: Basic HTTP
Description: Check all links that have the HTTP/HTTPS protocol.
Version: 1.0
Author: Janis Elsts

ModuleID: http
ModuleCategory: checker
ModuleContext: on-demand
ModuleLazyInit: true
ModuleClassName: blcHttpChecker
ModulePriority: -1
*/
namespace Blc\Abstract;

use Blc\Abstract\Checker;





/**
 * Base class for checkers that deal with HTTP(S) URLs.
 *
 * @package Broken Link Checker
 * @access public
 */
abstract class HttpCheckerBase extends Checker
{
    protected $headers = [];
    protected $acceptLanguage         = 'en-US,en;q=0.5';
    protected $userAgent              = "";
    protected string $splitOption = "#(;|,|\r\n|\n|\r)#";

    function clean_url($url)
    {
        $url = html_entity_decode($url);

        $ltrm = preg_quote(json_decode('"\u200E"'), '/');
        $url  = preg_replace(
            array(
                '/([\?&]PHPSESSID=\w+)$/i', // remove session ID
                '/(#[^\/]*)$/',             // and anchors/fragments
                '/&amp;/',                  // convert improper HTML entities
                '/([\?&]sid=\w+)$/i',       // remove another flavour of session ID
                '/' . $ltrm . '/',          // remove Left-to-Right marks that can show up when copying from Word.
            ),
            array('', '', '&', '', ''),
            $url
        );
        $url  = trim($url);

        return $url;
    }


    public static function is_error_code($http_code)
    {
        /*
        "Good" response codes are anything in the 2XX range (e.g "200 OK") and redirects  - the 3XX range.
            HTTP 401 Unauthorized is a special case that is considered OK as well. Other errors - the 4XX range -
            are treated as such. */
        $good_code = (($http_code >= 200) && ($http_code < 400)) || (401 === $http_code);
        return !$good_code;
    }

    /**
     * This checker only accepts HTTP(s) links.
     *
     * @param string     $url
     * @param array|bool $parsed
     * @return bool
     */
    function can_check($url, $parsed)
    {
        if (!isset($parsed['scheme'])) {
            return false;
        }

        return in_array(strtolower($parsed['scheme']), array('http', 'https'));
    }

    /**
     * Takes an URL and replaces spaces and some other non-alphanumeric characters with their urlencoded equivalents.
     *
     * @param string $url
     * @return string
     */
    function urlencodefix($url)
    {
        // TODO: Remove/fix this. Probably not a good idea to "fix" invalid URLs like that.
        return preg_replace_callback(
            '|[^a-z0-9\+\-\/\\#:.,;=?!&%@()$\|*~_]|i',
            fn($str) => rawurlencode($str[0]),
            $url
        );
    }
    protected function getLanguage()
    {

        $langCode = get_bloginfo('language');

        $short                       = explode('-', $langCode);
        $languageAccept[$short[0]] = $short[0];
        $languageAccept[$langCode] = $langCode;

        $q = 1.0;
        array_walk(
            $languageAccept,
            function (&$item) use (&$q) {
                if ($q < 1) {
                    $item .= ";q=$q";
                }

                $q = max(0.3, $q - 0.1);
            }
        );
        $languageAccept['en-US'] ??= 'en-US;q=0.2';
        $languageAccept['en']    ??= 'en;q=0.1';
        return join(',', $languageAccept);
    }

    protected function loadSignature($browser)
    {
        $file      = BLC_DIRECTORY_LEGACY . "/signatures/{$browser}.json";
        $signature = [];
        if (is_file($file)) {
            $signature = json_decode(file_get_contents($file), true);
        }

        if (!$signature) {
            $signature = $this->loadSignature('firefox');
        }

        return $signature;
    }

    protected function setSignature($signature)
    {

        $signature = $this->loadSignature($signature);
        if (isset($signature['Accept-Language'])) {
            $this->acceptLanguage = $signature['Accept-Language'];
        }
        $this->userAgent = $signature['userAgent'] ?? 'Wordpress Link Checker';
        $this->__set('headers', $signature['headers'] ?? []); //takes care of spliting
        return $signature;
    }



    public function removeHeader(string $header)
    {
        $key           = strtok($header, ':');
        $partialString = "$key:";
        $this->headers =  array_filter($this->headers, fn($item) => stripos($item, $partialString) !== 0);
    }

    public function replaceHeader(string $header)
    {
        $this->removeHeader($header);
        $this->addHeader($header);
    }
    public function addHeader(string $header)
    {
        $this->headers[] = $header;
    }
    public function clearHeaders()
    {
        $this->headers = [];
    }


    public function __set($name, $value)
    {
        $name = strtolower($name);

        switch ($name) {
            case 'language':
                if (\is_string($value)) {
                    $this->acceptLanguage = $value;
                }
                break;
            case 'headers':
                switch (true) {
                    case \is_array($value):
                        $this->headers = $value;
                        break;
                    case \is_object($value):
                        $this->headers = (array)$value;
                        break;
                    case \is_string($value):
                        $this->headers = preg_split($this->splitOption, $value);
                        break;
                }
                break;
            case 'signature':
                if (\is_string($value)) {
                    $this->setSignature($value);
                }

                break;
            default:
        }
    }
}
