<?php

namespace Ferno\Loco;


/**
 * Takes the input parsers and applies them all in turn. "Lazy" indicates
 * that as soon as a single parser matches, those matches are returned and
 * processing halts.
 * This is best used when the input parsers are mutually exclusive
 * callback should accept a single argument which is the single match
 * LazyAltParsers become risky when one is a proper prefix of another
 */
class LazyAltParser extends \Ferno\Loco\MonoParser
{
    public function __construct($internals, $callback = null)
    {
        if (count($internals) === 0) {
            throw new \Ferno\Loco\GrammarException("Can't make a " . get_class() . " without at least one internal parser.\n");
        }
        $this->internals = $internals;
        $this->string = "new " . get_class() . "(" . serialiseArray($internals) . ")";
        parent::__construct($internals, $callback);
    }

    /**
     * default callback: return the sole result unmodified
     */
    public function defaultCallback()
    {
        return func_get_arg(0);
    }
    public function getResult($string, $i = 0)
    {
        foreach ($this->internals as $internal) {
            try {
                $match = $internal->match($string, $i);
            } catch (\Ferno\Loco\ParseFailureException $e) {
                continue;
            }
            return array("j" => $match["j"], "args" => array($match["value"]));
        }
        throw new \Ferno\Loco\ParseFailureException($this . " could not match another token", $i, $string);
    }

    /**
     * Nullable if any internal is nullable.
     */
    public function evaluateNullability()
    {
        foreach ($this->internals as $internal) {
            if ($internal->nullable) {
                return true;
            }
        }
        return false;
    }

    /**
     * every internal is potentially a first.
     */
    public function firstSet()
    {
        return $this->internals;
    }
}

