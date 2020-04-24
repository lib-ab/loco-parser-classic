<?php

namespace Ferno\Loco;

/**
 * UTF-8 parser parses one valid UTF-8 character and returns the
 * resulting code point.
 * Callback should accept the character (in the form of bytes)
 */
class Utf8Parser extends \Ferno\Loco\StaticParser
{
    # Some basic useful information about each possible byte
    # sequence i.e. prefix and number of free bits
    # binary expressions for extracting useful information
    # Pre-calculated. Could be calculated on the fly but nobody caaares
    private static $expressions = array(array(
        "numbytes" => 1,
        "freebits" => array(7),
        # 0xxxxxxx
        "mask" => "\200",
        # 10000000
        "result" => "\000",
        # 00000000
        "extract" => "\177",
        # 01111111
        "mincodepoint" => 0,
        "maxcodepoint" => 127,
    ), array(
        "numbytes" => 2,
        "freebits" => array(5, 6),
        # 110xxxxx 10xxxxxx
        "mask" => "\340",
        # 11100000 11000000
        "result" => "\300",
        # 11000000 10000000
        "extract" => "\037?",
        # 00011111 00111111
        "mincodepoint" => 128,
        "maxcodepoint" => 2047,
    ), array(
        "numbytes" => 3,
        "freebits" => array(4, 6, 6),
        # 1110xxxx 10xxxxxx 10xxxxxx
        "mask" => "\360",
        # 11110000 11000000 11000000
        "result" => "\340",
        # 11100000 10000000 10000000
        "extract" => "\017??",
        # 00001111 00111111 00111111
        "mincodepoint" => 2048,
        "maxcodepoint" => 65535,
    ), array(
        "numbytes" => 4,
        "freebits" => array(3, 6, 6, 6),
        # 11110xxx 10xxxxxx 10xxxxxx 10xxxxxx
        "mask" => "\370",
        # 11111000 11000000 11000000 11000000
        "result" => "\360",
        # 11110000 10000000 10000000 10000000
        "extract" => "\007???",
        # 00000111 00111111 00111111 00111111
        "mincodepoint" => 65536,
        "maxcodepoint" => 2097151,
    ));
    // Unicode control characters
    // http://www.fileformat.info/info/unicode/category/Cc/list.htm
    public static $controls = array(0x0, 0x1, 0x2, 0x3, 0x4, 0x5, 0x6, 0x7, 0x8, 0x9, 0xa, 0xb, 0xc, 0xd, 0xe, 0xf, 0x10, 0x11, 0x12, 0x13, 0x14, 0x15, 0x16, 0x17, 0x18, 0x19, 0x1a, 0x1b, 0x1c, 0x1d, 0x1e, 0x1f, 0x7f, 0x80, 0x81, 0x82, 0x83, 0x84, 0x85, 0x86, 0x87, 0x88, 0x89, 0x8a, 0x8b, 0x8c, 0x8d, 0x8e, 0x8f, 0x90, 0x91, 0x92, 0x93, 0x94, 0x95, 0x96, 0x97, 0x98, 0x99, 0x9a, 0x9b, 0x9c, 0x9d, 0x9e, 0x9f);
    // http://en.wikipedia.org/wiki/Valid_characters_in_XML#Non-restricted_characters
    private static $xmlSafeRanges = array(
        // The only C0 controls acceptable in XML 1.0 and 1.1
        array("bottom" => 0x9, "top" => 0xa),
        array("bottom" => 0xd, "top" => 0xd),
        // Non-control characters in the Basic Latin block, excluding the last C0 control
        array("bottom" => 0x20, "top" => 0x7e),
        // The only C1 control character accepted in both XML 1.0 and XML 1.1
        array("bottom" => 0x85, "top" => 0x85),
        // Rest of BMP, excluding all non-characters (such as surrogates)
        array("bottom" => 0xa0, "top" => 0xd7ff),
        array("bottom" => 0xe000, "top" => 0xfdcf),
        array("bottom" => 0xfde0, "top" => 0xfffd),
        // Exclude all non-characters in supplementary planes
        array("bottom" => 0x10000, "top" => 0x1fffd),
        array("bottom" => 0x20000, "top" => 0x2fffd),
        array("bottom" => 0x30000, "top" => 0x3fffd),
        array("bottom" => 0x40000, "top" => 0x4fffd),
        array("bottom" => 0x50000, "top" => 0x5fffd),
        array("bottom" => 0x60000, "top" => 0x6fffd),
        array("bottom" => 0x70000, "top" => 0x7fffd),
        array("bottom" => 0x80000, "top" => 0x8fffd),
        array("bottom" => 0x90000, "top" => 0x9fffd),
        array("bottom" => 0xa0000, "top" => 0xafffd),
        array("bottom" => 0xb0000, "top" => 0xbfffd),
        array("bottom" => 0xc0000, "top" => 0xcfffd),
        array("bottom" => 0xd0000, "top" => 0xdfffd),
        array("bottom" => 0xe0000, "top" => 0xefffd),
        array("bottom" => 0xf0000, "top" => 0xffffd),
        array("bottom" => 0x100000, "top" => 0x10fffd),
    );
    # should contain a blacklist of CHARACTERS (i.e. strings), not code points
    private $blacklist;
    public function __construct($blacklist = array(), $callback = null)
    {
        $this->blacklist = $blacklist;
        $this->string = "new " . get_class() . "(" . serialiseArray($blacklist) . ")";
        parent::__construct($callback);
    }

    /**
     * default callback: just return the string that was matched
     */
    public function defaultCallback()
    {
        return func_get_arg(0);
    }
    public function getResult($string, $i = 0)
    {
        foreach (self::$expressions as $expression) {
            $length = $expression["numbytes"];
            // string is too short to accommodate this expression
            // try next expression
            // (since expressions are in increasing order of size, this is pointless)
            if (strlen($string) < $i + $length) {
                continue;
            }
            $character = substr($string, $i, $length);
            // string doesn't match expression: try next expression
            if (($character & $expression["mask"]) !== $expression["result"]) {
                continue;
            }
            // Character is blacklisted: abandon effort entirely
            if (in_array($character, $this->blacklist)) {
                break;
            }
            // get code point
            $codepoint = 0;
            foreach ($expression["freebits"] as $byteId => $freebits) {
                $codepoint <<= $freebits;
                $codepoint += ord($string[$i + $byteId] & $expression["extract"][$byteId]);
            }
            // overlong encoding: not valid UTF-8, abandon effort entirely
            if ($codepoint < $expression["mincodepoint"]) {
                break;
            }
            // make sure code point falls inside a safe range
            foreach (self::$xmlSafeRanges as $range) {
                // code point isn't in range: try next range
                if ($codepoint < $range["bottom"] || $range["top"] < $codepoint) {
                    continue;
                }
                // code point is in a safe range.
                // OK: return
                return array("j" => $i + $length, "args" => array($character));
            }
            // code point isn't safe: abandon effort entirely
            break;
        }
        throw new \Ferno\Loco\ParseFailureException($this . " could not find a UTF-8 character", $i, $string);
    }

    /**
     * UTF-8 parser is not nullable.
     */
    public function evaluateNullability()
    {
        return false;
    }

    /**
     * convert a Unicode code point into UTF-8 bytes
     */
    public static function getBytes($codepoint)
    {
        foreach (self::$expressions as $expression) {
            // next expression
            if ($codepoint > $expression["maxcodepoint"]) {
                continue;
            }
            // pull out basic numbers
            $string = "";
            foreach (array_reverse($expression["freebits"]) as $freebits) {
                $x = $codepoint & (1 << $freebits) - 1;
                $string = chr($x) . $string;
                $codepoint >>= $freebits;
            }
            // add "cladding"
            $string |= $expression["result"];
            return $string;
        }
    }
}