<?php

/*
 * This file is part of the BibTex Parser.
 *
 * (c) Renan de Lima Barbosa <renandelima@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RenanBr\BibTexParser;

class Parser
{
    const NONE = 'none';
    const COMMENT = 'comment';
    const TYPE = 'type';
    const POST_TYPE = 'post_type';
    const KEY = 'key';
    const POST_KEY = 'post_key';
    const VALUE = 'value';
    const RAW_VALUE = 'raw_value';
    const BRACED_VALUE = 'braced_value';
    const QUOTED_VALUE = 'quoted_value';
    const ORIGINAL_ENTRY = 'original_entry';

    /**
     * @var string
     */
    private $state;

    /**
     * @var string
     */
    private $stateAfterCommentIsGone;

    /**
     * @var string
     */
    private $buffer;

    /**
     * @var string
     */
    private $originalEntry;

    /**
     * @var int
     */
    private $originalEntryOffset;

    /**
     * @var int
     */
    private $line;

    /**
     * @var int
     */
    private $column;

    /**
     * @var int
     */
    private $offset;

    /**
     * @var bool
     */
    private $isValueEscaped;

    /**
     * @var bool
     */
    private $mayConcatenateValue;

    /**
     * @var string
     */
    private $valueDelimiter;

    /**
     * @var int
     */
    private $braceLevel = 0;

    /**
     * @var ListenerInterface[]
     */
    private $listeners = [];

    public function addListener(ListenerInterface $listener)
    {
        $this->listeners[] = $listener;
    }

    /**
     * @param string $file
     * @throws RenanBr\BibTexParser\ParseException If $file given is not a valid BibTeX.
     * @throws ErrorException If $file given is not readable.
     */
    public function parseFile($file)
    {
        $handle = fopen($file, 'r');
        try {
            $this->reset();
            while (!feof($handle)) {
                $buffer = fread($handle, 128);
                $this->parse($buffer);
            }
            $this->checkFinalStatus();
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param string $string
     * @throws RenanBr\BibTexParser\ParseException If $string given is not a valid BibTeX.
     */
    public function parseString($string)
    {
        $this->reset();
        $this->parse($string);
        $this->checkFinalStatus();
    }

    private function parse($text)
    {
        $length = mb_strlen($text);
        for ($position = 0; $position < $length; $position++) {
            $char = mb_substr($text, $position, 1);
            $this->read($char);
            if ("\n" == $char) {
                $this->line++;
                $this->column = 1;
            } else {
                $this->column++;
            }
            $this->offset++;
        }
    }

    /**
     * It's called when parsing has been done, so it checks whether the status
     * is ok or not.
     */
    private function checkFinalStatus()
    {
        $current = $this->state;
        $previous = $this->stateAfterCommentIsGone;
        if (self::NONE != $current || (self::COMMENT == $current && self::NONE != $previous)) {
            $this->throwException("\0");
        }
    }

    private function reset()
    {
        $this->state = self::NONE;
        $this->stateAfterCommentIsGone = null;
        $this->buffer = '';
        $this->originalEntry = '';
        $this->originalEntryOffset = null;
        $this->line = 1;
        $this->column = 1;
        $this->offset = 0;
        $this->mayConcatenateValue = false;
        $this->isValueEscaped = false;
        $this->valueDelimiter = null;
        $this->braceLevel = 0;
    }

    private function read($char)
    {
        $previousState = $this->state;

        switch ($this->state) {
            case self::NONE:
                $this->readNone($char);
                break;
            case self::COMMENT:
                $this->readComment($char);
                break;
            case self::TYPE:
                $this->readType($char);
                break;
            case self::POST_TYPE:
                $this->readPostType($char);
                break;
            case self::KEY:
                $this->readKey($char);
                break;
            case self::POST_KEY:
                $this->readPostKey($char);
                break;
            case self::VALUE:
                $this->readValue($char);
                break;
            case self::RAW_VALUE:
                $this->readRawValue($char);
                break;
            case self::QUOTED_VALUE:
            case self::BRACED_VALUE:
                $this->readDelimitedValue($char);
                break;
        }

        $this->readOriginalEntry($char, $previousState);
    }

    private function readNone($char)
    {
        if ('%' == $char) {
            $this->stateAfterCommentIsGone = self::NONE;
            $this->state = self::COMMENT;
        } elseif ('@' == $char) {
            $this->state = self::TYPE;
        } elseif (!$this->isWhitespace($char)) {
            $this->throwException($char);
        }
    }

    private function readComment($char)
    {
        if ("\n" == $char) {
            $this->state = $this->stateAfterCommentIsGone;
        }
    }

    private function readType($char)
    {
        if (preg_match('/^[a-zA-Z]$/u', $char)) {
            $this->appendToBuffer($char);
        } else {
            $this->throwExceptionIfBufferIsEmpty($char);
            $this->triggerListenersWithCurrentBuffer();

            // once $char isn't a valid character
            // it must be interpreted as POST_TYPE
            $this->state = self::POST_TYPE;
            $this->readPostType($char);
        }
    }

    private function readPostType($char)
    {
        if ('%' == $char) {
            $this->stateAfterCommentIsGone = self::POST_TYPE;
            $this->state = self::COMMENT;
        } elseif ('{' == $char) {
            $this->state = self::KEY;
        } elseif (!$this->isWhitespace($char)) {
            $this->throwException($char);
        }
    }

    private function readKey($char)
    {
        if (preg_match('/^[^\s={},"%]$/u', $char)) {
            $this->appendToBuffer($char);
        } elseif ($this->isWhitespace($char) && empty($this->buffer)) {
            // skip
        } elseif ('%' == $char && empty($this->buffer)) {
            // we can't move to POST_KEY, because buffer buffer is empty
            // so, after comment is gone, we are still looking for a key
            $this->stateAfterCommentIsGone = self::KEY;
            $this->state = self::COMMENT;
        } elseif ('}' == $char) {
            $this->state = self::NONE;
        } else {
            $this->throwExceptionIfBufferIsEmpty($char);
            $this->triggerListenersWithCurrentBuffer();

            // once $char isn't a valid character
            // it must be interpreted as POST_TYPE
            $this->state = self::POST_KEY;
            $this->readPostKey($char);
        }
    }

    private function readPostKey($char)
    {
        if ('%' == $char) {
            $this->stateAfterCommentIsGone = self::POST_KEY;
            $this->state = self::COMMENT;
        } elseif ('=' == $char) {
            $this->state = self::VALUE;
        } elseif ('}' == $char) {
            $this->state = self::NONE;
        } elseif (',' == $char) {
            $this->state = self::KEY;
        } elseif (!$this->isWhitespace($char)) {
            $this->throwException($char);
        }
    }

    private function readValue($char)
    {
        if (preg_match('/^[a-zA-Z0-9]$/u', $char)) {
            // when $mayConcatenateValue is true it means there is another
            // value defined before it, so a concatenator char is expected (or
            // a comment as well)
            if ($this->mayConcatenateValue) {
                $this->throwException($char);
            }
            $this->state = self::RAW_VALUE;
            $this->readRawValue($char);
        } elseif ('%' == $char) {
            $this->stateAfterCommentIsGone = self::VALUE;
            $this->state = self::COMMENT;
        } elseif ('"' == $char) {
            // this verification is here for the same reason of the first case
            if ($this->mayConcatenateValue) {
                $this->throwException($char);
            }
            $this->valueDelimiter = '"';
            $this->state = self::QUOTED_VALUE;
        } elseif ('{' == $char) {
            // this verification is here for the same reason of the first case
            if ($this->mayConcatenateValue) {
                $this->throwException($char);
            }
            $this->valueDelimiter = '}';
            $this->state = self::BRACED_VALUE;
        } elseif ('#' == $char || ',' == $char || '}' == $char) {
            if (!$this->mayConcatenateValue) {
                // it expects some value
                $this->throwException($char);
            }
            $this->mayConcatenateValue = false;
            if (',' == $char) {
                $this->state = self::KEY;
            } elseif ('}' == $char) {
                $this->state = self::NONE;
            }
        }
    }

    private function readRawValue($char)
    {
        if (preg_match('/^[a-zA-Z0-9]$/u', $char)) {
            $this->appendToBuffer($char);
        } else {
            $this->throwExceptionIfBufferIsEmpty($char);
            $this->triggerListenersWithCurrentBuffer();

            if ('%' == $char) {
                $this->mayConcatenateValue = true;
                $this->stateAfterCommentIsGone = self::VALUE;
                $this->state = self::COMMENT;
            } else {
                // once $char isn't a valid character
                // it must be interpreted as VALUE
                $this->mayConcatenateValue = true;
                $this->state = self::VALUE;
                $this->readValue($char);
            }
        }
    }

    private function readDelimitedValue($char)
    {
        if ($this->isValueEscaped) {
            $this->isValueEscaped = false;
            if ($this->valueDelimiter != $char && '\\' != $char && '%' != $char) {
                $this->appendToBuffer('\\');
            }
            $this->appendToBuffer($char);
        } elseif ('}' == $this->valueDelimiter && '{' == $char) {
            $this->braceLevel++;
            $this->appendToBuffer($char);
        } elseif ($this->valueDelimiter == $char) {
            if (0 == $this->braceLevel) {
                $this->triggerListenersWithCurrentBuffer();
                $this->mayConcatenateValue = true;
                $this->state = self::VALUE;
            } else {
                $this->braceLevel--;
                $this->appendToBuffer($char);
            }
        } elseif ('\\' == $char) {
            $this->isValueEscaped = true;
        } else {
            $this->appendToBuffer($char);
        }
    }

    private function readOriginalEntry($char, $previousState)
    {
        // normalize states, because comments always belongs to some context
        $currentState = $this->state == self::COMMENT
            ? $this->stateAfterCommentIsGone
            : $this->state;
        $previousState = $previousState == self::COMMENT
            ? $currentState // assume current state, whatever it is
            : $previousState;

        // check if we are reading an entry character
        $isEntryChar =
            // when $char simply belongs to an entry
            $currentState != self::NONE ||
            // when $previousState is not equal to "none", it means that $char
            // has just changed the state from something to "none", it happens
            // when $char closes an entry, for example
            $previousState != self::NONE;

        if ($isEntryChar) {
            // append to the buffer
            if (empty($this->originalEntry)) {
                $this->originalEntryOffset = $this->offset;
            }
            $this->originalEntry .= $char;
        } elseif (!empty($this->originalEntry)) {
            // send original value to the listeners
            $context = [
                'state' => self::ORIGINAL_ENTRY,
                'offset' => $this->originalEntryOffset,
                'length' => $this->offset - $this->originalEntryOffset
            ];
            $this->triggerListeners($this->originalEntry, $context);
            $this->originalEntryOffset = null;
            $this->originalEntry = '';
        }
    }

    private function throwExceptionIfBufferIsEmpty($char)
    {
        if (empty($this->buffer)) {
            $this->throwException($char);
        }
    }

    private function throwException($char)
    {
        // avoid var_export() weird treatment for \0
        $char = "\0" == $char ? "'\\0'" : var_export($char, true);

        throw new ParseException(sprintf(
            "Unexpected character %s at line %d column %d",
            $char,
            $this->line,
            $this->column
        ));
    }

    private function appendToBuffer($char)
    {
        if (empty($this->buffer)) {
            $this->bufferOffset = $this->offset;
        }
        $this->buffer .= $char;
    }

    private function triggerListenersWithCurrentBuffer()
    {
        $context = [
            'state' => $this->state,
            'offset' => $this->bufferOffset,
            'length' => $this->offset - $this->bufferOffset
        ];
        $this->triggerListeners($this->buffer, $context);
        $this->bufferOffset = null;
        $this->buffer = '';
    }

    private function triggerListeners($text, array $context)
    {
        foreach ($this->listeners as $listener) {
            $listener->bibTexUnitFound($text, $context);
        }
    }

    private function isWhitespace($char)
    {
        return ' ' == $char || "\t" == $char || "\n" == $char || "\r" == $char;
    }
}
