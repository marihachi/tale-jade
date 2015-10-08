<?php

namespace Tale\Jade;

use Tale\Jade\Lexer\Exception;

/**
 * The Lexer parses the input string into tokens
 * that can be worked with easier
 *
 * Tokens are defined as single units of code
 * (e.g. tag, class, id, attributeStart, attribute, attributeEnd)
 *
 * These will run through the parser and be converted to an AST
 *
 * The lexer works sequential, ->lex will return a generator and
 * you can read that generator in any manner you like.
 * The generator will produce valid tokens until the end of the passed
 * input.
 *
 * @package Tale\Jade
 */
class Lexer
{

    /**
     * Tab Indentation (\t)
     */
    const INDENT_TAB = "\t";
    /**
     * Space Indentation ( )
     */
    const INDENT_SPACE = ' ';

    /**
     * The current input string
     *
     * @var string
     */
    private $_input;

    /**
     * The total length of the current input
     *
     * @var int
     */
    private $_length;

    /**
     * The current position inside the input string
     *
     * @var int
     */
    private $_position;

    /**
     * The current line we are on
     *
     * @var int
     */
    private $_line;

    /**
     * The current offset in a line we are on
     * Resets on each new line and increases on each read character
     *
     * @var int
     */
    private $_offset;

    /**
     * The current indentation level we are on
     *
     * @var int
     */
    private $_level;

    /**
     * The current indentation character
     *
     * @var string
     */
    private $_indentStyle;

    /**
     * The width of the indentation, meaning how often $_indentStyle
     * is repeated for each $_level
     *
     * @var string
     */
    private $_indentWidth;

    /**
     * The last result gotten via ->peek()
     *
     * @var string
     */
    private $_lastPeekResult;

    /**
     * The last matches gotten via ->match()
     *
     * @var array
     */
    private $_lastMatches;

    /**
     * Creates a new lexer instance
     * The options should be an associative array
     *
     * Valid options are:
     *      indentStyle: The indentation character (auto-detected)
     *      indentWidth: How often to repeat indentStyle (auto-detected)
     *      encoding: The encoding when working with mb_*-functions
     *
     * Passing an indentation-style forces you to stick to that style.
     * If not, the lexer will assume the first indentation type it finds as the indentation
     * Mixed indentation is not possible, since it would be a bitch to calculate without
     * taking away configuration freedom
     *
     * @param array|null $options The options passed to the lexer instance
     *
     * @throws \Exception
     */
    public function __construct(array $options = null)
    {

        $this->_options = array_replace([
            'indentStyle' => null,
            'indentWidth' => null,
            'encoding' => mb_internal_encoding()
        ], $options ? $options : []);

        //Validate options
        if (!in_array($this->_options['indentStyle'], [null, self::INDENT_TAB, self::INDENT_SPACE]))
            throw new \Exception(
                "indentStyle needs to be null or one of the INDENT_* constants of the lexer"
            );

        if (!is_null($this->_options['indentWidth']) &&
            (!is_int($this->_options['indentWidth']) || $this->_options['indentWidth'] < 1)
        )
            throw new \Exception(
                "indentWidth needs to be a integer above 0"
            );
    }

    /**
     * Returns the current input-string worked on
     *
     * @return string
     */
    public function getInput()
    {
        return $this->_input;
    }

    /**
     * Returns the total length of the current input-string
     *
     * @return int
     */
    public function getLength()
    {
        return $this->_length;
    }

    /**
     * Returns the total position in the current input-string
     *
     * @return int
     */
    public function getPosition()
    {
        return $this->_position;
    }

    /**
     * Returns the line we are working on in the current input-string
     *
     * @return int
     */
    public function getLine()
    {
        return $this->_line;
    }

    /**
     * Gets the offset on a line (Line-start is offset 0) in the current
     * input-string
     *
     * @return int
     */
    public function getOffset()
    {
        return $this->_offset;
    }

    /**
     * Returns the current indentation level we are on
     *
     * @return int
     */
    public function getLevel()
    {
        return $this->_level;
    }

    /**
     * Returns the detected or previously passed indentation style
     *
     * @return string
     */
    public function getIndentStyle()
    {
        return $this->_indentStyle;
    }

    /**
     * Returns the detected or previously passed indentation width
     *
     * @return int
     */
    public function getIndentWidth()
    {
        return $this->_indentWidth;
    }

    /**
     * Returns the last result of ->peek()
     *
     * @return string|null
     */
    public function getLastPeekResult()
    {
        return $this->_lastPeekResult;
    }

    /**
     * Returns the last array of matches through ->match
     *
     * @return array|null
     */
    public function getLastMatches()
    {
        return $this->_lastMatches;
    }

    /**
     * Returns a generator that will lex the passed $input
     * sequentially.
     * If you don't move the generator, the lexer does nothing.
     * Only as soon as you iterate the generator or call next()/current() on it
     * the lexer will start it's work and spit out tokens sequentially
     *
     * Tokens are always an array and always provide the following keys:
     * ['type' => The token type, 'line' => The line this token is on, 'offset' => The offset this token is at]
     *
     * @param string $input The Jade-string to lex into tokens
     *
     * @return \Generator A generator that can be iterated sequentially
     */
    public function lex($input)
    {

        $this->_input = rtrim(str_replace([
            "\r", "\0"
        ], '', $input))."\n";
        $this->_length = $this->strlen($this->_input);
        $this->_position = 0;

        $this->_line = 1;
        $this->_offset = 0;
        $this->_level = 0;

        $this->_indentStyle = $this->_options['indentStyle'];
        $this->_indentWidth = $this->_options['indentWidth'];

        $this->_lastPeekResult = null;
        $this->_lastMatches = null;

        foreach ($this->scanFor([
            'newLine', 'indent',
            'import',
            'block',
            'conditional', 'each', 'case', 'when', 'do', 'while',
            'mixin', 'mixinCall',
            'doctype',
            'tag', 'classes', 'id', 'attributes',
            'assignment',
            'comment', 'filter',
            'expression',
            'markup',
            'textLine',
            'text'
        ], true) as $token)
            yield $token;
    }

    /**
     * Dumps jade-input into a set of string-represented tokens
     * This makes debugging the lexer easier.
     *
     * @param string $input The jade input to dump the tokens of
     */
    public function dump($input)
    {

        foreach ($this->lex($input) as $token) {

            $type = $token['type'];
            $line = $token['line'];
            $offset = $token['offset'];
            unset($token['type'], $token['line'], $token['offset']);

            echo "[$type($line:$offset)";
            $vals = implode(', ', array_map(function($key, $value) {

                return "$key=$value";
            }, array_keys($token), $token));

            if (!empty($vals))
                echo " $vals";

            echo ']';

            if ($type === 'newLine')
                echo "\n";
        }
    }

    /**
     * Checks if our read pointer is at the end of the code
     *
     * @return bool
     */
    protected function isAtEnd()
    {

        return $this->_position >= $this->_length;
    }

    /**
     * Shows the next characters in our input
     * Pass a $length to get more than one character.
     * The character's _won't_ be consumed here, they are just shown.
     * The position pointer won't be moved forward
     *
     * @param int $length The length of the string we want to peek on
     *
     * @return string The peeked string
     */
    protected function peek($length = 1)
    {

        $this->_lastPeekResult = $this->substr($this->_input, 0, $length);
        return $this->_lastPeekResult;
    }

    /**
     * Consumes the last result that has been peeked
     * or the length you passed from the current input.
     *
     * Internally $input = substr($input, $length) is done,
     * so everything _before_ the consumed length will be cut off and
     * removed from the RAM (since we probably tokenized it already,
     * remember? sequential shit etc.?)
     *
     * @see \Tale\Jade\Lexer->peek()
     * @param int|null $length The length to consume or none, to use the length of the last peeked string
     *
     * @return $this
     * @throws \Tale\Jade\Lexer\Exception
     */
    protected function consume($length = null)
    {

        if ($length === null) {

            if ($this->_lastPeekResult === null)
                $this->throwException(
                    "Failed to consume: Nothing has been peeked and you"
                    ." didnt pass a length to consume"
                );

            $length = $this->strlen($this->_lastPeekResult);
        }

        $this->_input = $this->substr($this->_input, $length);
        $this->_position += $length;
        $this->_offset += $length;
        return $this;
    }

    /**
     * Peeks and consumes characters until the result of
     * the passed callback is false.
     *
     * The callback takes the current character as the first argument.
     *
     * This works great with ctype_*-functions
     *
     * If the last character doesn't match, it also won't be consumed
     * You can always go on reading right after a call to ->read()
     *
     * e.g.
     * $alNumString = $this->read('ctype_alnum')
     * $spaces = $this->read('ctype_space')
     *
     * @param callable $callback The callback to check the current character against
     * @param int $length The length to peek. This will also increase the length of the characters passed to the callback
     *
     * @return string The read string
     * @throws \Exception
     */
    protected function read($callback, $length = 1)
    {

        if (!is_callable($callback))
            throw new \Exception(
                "Argument 1 passed to peekWhile needs to be callback"
            );

        $result = '';
        while (!$this->isAtEnd() && $callback($this->peek($length)))
        {

            //Keep $_line and $_offset updated
            $newLines = $this->substr_count($this->_lastPeekResult, "\n");
            $this->_line += $newLines;

            if ($newLines) {

                if (strlen($this->_lastPeekResult) === 1)
                    $this->_offset = 0;
                else {

                    $parts = explode("\n", $this->_lastPeekResult);
                    $this->_offset = strlen($parts[count($parts) - 1]) - 1;
                }
            }

            $this->consume();
            $result .= $this->_lastPeekResult;
        }

        return $result;
    }

    /**
     * Reads all TAB (\t) and SPACE ( ) characters until there's none
     * of those two found anymore
     *
     * This is primarily used to parse the indentation at the begin of each line
     *
     * @return string The spaces that have been found
     * @throws \Exception
     */
    protected function readSpaces()
    {

        return $this->read(function($char) {

            return $char === self::INDENT_SPACE || $char === self::INDENT_TAB;
        });
    }

    /**
     * Reads a "value", 'value', value style string
     * really gracefully
     *
     * It will stop on all chars passed to $breakChars as well as a closing )
     * when _not_ inside an expression initiated with either
     * ", ', (, [ or {.
     *
     * $breakChars might be [','] as an example to read sequential arguments
     * into an array. Scan for ',', skip spaces, repeat readBracketContents
     *
     * Brackets are counted, strings are respected.
     *
     * Inside a " string, \" escaping is possible, inside a ' string, \' escaping
     * is possible
     *
     * As soon as a ) is found and we're outside a string and outside any kind of bracket,
     * the reading will stop and the value, including any quotes, will be returned
     *
     * Examples:
     * ('`' marks the parts that are read, understood and returned by this function)
     *
     * (arg1=`abc`, arg2=`"some expression"`, `'some string expression'`)
     * some-mixin(`'some arg'`, `[1, 2, 3, 4]`, `(isset($complex) ? $complex : 'complex')`)
     * and even
     * some-mixin(callback=function($input) { return trim($input, '\'"'); })
     *
     * @param array|null $breakChars The chars to break on.
     *
     * @return string The (possibly quote-enclosed) result string
     */
    protected function readBracketContents(array $breakChars = null)
    {

        $breakChars = $breakChars ? $breakChars : [];
        $value = '';
        $prev = null;
        $char = null;
        $level = 0;
        $inString = false;
        $stringType = null;
        $break = false;
        while (!$this->isAtEnd() && !$break) {

            if ($this->isAtEnd())
                break;

            $prev = $char;
            $char = $this->peek();

            switch ($char) {
                case '"':
                case '\'':

                    if ($inString && $stringType === $char && $prev !== '\\')
                        $inString = false;
                    else if (!$inString) {

                        $inString = true;
                        $stringType = $char;
                    }
                    break;
                case '(':
                case '[':
                case '{':

                    if (!$inString)
                        $level++;
                    break;
                case ')':
                case ']':
                case '}':

                    if ($inString)
                        break;

                    if ($level === 0) {

                        $break = true;
                        break;
                    }

                    $level--;
                    break;
            }

            if (in_array($char, $breakChars, true) && !$inString && $level === 0)
                $break = true;

            if (!$break) {

                $value .= $char;
                $this->consume();
            }
        }

        return trim($value);
    }

    /**
     * Matches a pattern against the start of the current $input
     * Notice that this always takes the start of the current pointer
     * position as a reference, since `consume` means cutting of the front
     * of the input string
     *
     * After a match was successful, you can retrieve the matches
     * with ->getMatch() and consume the whole match with ->consumeMatch()
     *
     * ^ gets automatically prepended to the pattern (since it makes no sense for
     * a sequential lexer to search _inside_ the input)
     *
     * @param string $pattern The regular expression without delimeters and a ^-prefix
     * @param string $modifiers The usual PREG RegEx-modifiers
     *
     * @return bool
     */
    protected function match($pattern, $modifiers = '')
    {

        return preg_match(
            "/^$pattern/$modifiers",
            $this->_input,
            $this->_lastMatches
        ) ? true : false;
    }

    /**
     * Consumes a match previously read and matched by ->match()
     *
     * @return \Tale\Jade\Lexer
     */
    protected function consumeMatch()
    {

        //Make sure we don't consume matched newlines (We match for them sometimes)
        //We need the newLine tokens and don't want them consumed here.
        $match = $this->_lastMatches[0] !== "\n" ? rtrim($this->_lastMatches[0], "\n") : $this->_lastMatches[0];
        return $this->consume($this->strlen($match));
    }

    /**
     * Gets a match from the last ->match() call
     *
     * @param int|string $index The index of the usual PREG $matches argument
     *
     * @return mixed|null The value of the match or null, if none found
     */
    protected function getMatch($index)
    {

        return isset($this->_lastMatches[$index]) ? $this->_lastMatches[$index] : null;
    }

    /**
     * Keeps scanning for all types of tokens passed
     * as the first argument.
     *
     * If one token is encountered that's not in $scans, the function breaks
     * or throws an exception, if the second argument is true
     *
     * The passed scans get converted to methods
     * e.g. newLine => scanNewLine, blockExpansion => scanBlockExpansion etc.
     *
     * @param array $scans The scans to perform
     * @param bool|false $throwException Throw an exception if no tokens in $scans found anymore
     *
     * @return \Generator The generator yielding all tokens found
     * @throws \Tale\Jade\Lexer\Exception
     */
    protected function scanFor(array $scans, $throwException = false)
    {

        while (!$this->isAtEnd()) {

            $found = false;
            foreach ($scans as $name) {

                foreach (call_user_func([$this, 'scan'.ucfirst($name)]) as $token) {

                    $found = true;
                    yield $token;
                }

                if ($found)
                    continue 2;
            }

            $spaces = $this->readSpaces();
            if (!empty($spaces) && !$this->isAtEnd())
                continue;

            if ($throwException) {

                $this->throwException(
                    'Unexpected `'.htmlentities($this->peek(20), \ENT_QUOTES).'`, '
                    .implode(', ', $scans).' expected'
                );
            } else
                return;
        }
    }

    /**
     * Creates a new token
     * A token is an associative array.
     * The following keys _always_ exist:
     *
     * type: The type of the node (e.g. newLine, tag, class, id)
     * line: The line we encountered this token on
     * offset: The offset on a line we encountered it on
     *
     * Before adding a new token-type, make sure that the Parser knows how
     * to handle it and the Compiler knows how to compile it.
     *
     * @param string $type The type to give that token
     *
     * @return array The token
     */
    protected function createToken($type)
    {

        return [
            'type' => $type,
            'line' => $this->_line,
            'offset' => $this->_offset
        ];
    }

    /**
     * Scans for a specific token-type based on a pattern
     * and converts it to a valid token automatically
     *
     * All matches that have a name (RegEx (?<name>...)-directive
     * will directly get a key with that name and value
     * on the token array
     *
     * For matching, ->match() is used internally
     *
     * @param string $type The token type to create, if matched
     * @param string $pattern The pattern to match
     * @param string $modifiers The regex-modifiers for the pattern
     *
     * @return \Generator
     */
    protected function scanToken($type, $pattern, $modifiers = '')
    {

        if (!$this->match($pattern, $modifiers))
            return;

        $this->consumeMatch();
        $token = $this->createToken($type);
        foreach ($this->_lastMatches as $key => $value) {

            //We append all STRING-Matches (?<name>) to the token
            if (is_string($key)) {

                $token[$key] = empty($value) ? null : $value;
            }
        }

        yield $token;
    }

    /**
     * Scans for indentation and automatically keeps
     * the $_level updated through all tokens
     * Upon reaching a higher level, an <indent>-token is
     * yielded, upon reaching a lower level, an <outdent>-token is yielded
     *
     * If you outdented 3 levels, 3 <outdent>-tokens are yielded
     *
     * The first indentation this function encounters will be used
     * as the indentation style for this document.
     *
     * You can indent with everything between 1 space and a few million tabs
     * other than most Jade implementations
     *
     * @return \Generator|void
     * @throws \Tale\Jade\Lexer\Exception
     */
    protected function scanIndent()
    {

        if ($this->_offset !== 0 || !$this->match("([\t ]*)"))
            return;

        $this->consumeMatch();
        $indent = $this->getMatch(1);

        //If this is an empty line, we ignore the indentation completely.
        foreach ($this->scanNewLine() as $token) {

            yield $token;
            return;
        }

        $oldLevel = $this->_level;
        if (!empty($indent)) {

            $spaces = $this->strpos($indent, ' ') !== false;
            $tabs = $this->strpos($indent, "\t") !== false;
            $mixed = $spaces && $tabs;

            //Don't allow mixed indentation, this will just confuse the lexer
            if ($mixed)
                $this->throwException(
                    "Mixed indentation style encountered. "
                    ."Dont mix tabs and spaces. Stick to one of both."
                );

            //Validate the indentation style
            $indentStyle = $tabs ? self::INDENT_TAB : self::INDENT_SPACE;
            if ($this->_indentStyle && $this->_indentStyle !== $indentStyle)
                $this->throwException(
                    "Mixed indentation style encountered. "
                    ."You used another indentation style in this line than in "
                    ."previous lines. Dont do that."
                );

            //Validate the indentation width
            if (!$this->_indentWidth)
                //We will use the pretty first indentation as our indent width
                $this->_indentWidth = $this->strlen($indent);

            $this->_level = intval(round($this->strlen($indent) / $this->_indentWidth));

            if ($this->_level > $oldLevel + 1)
                $this->throwException(
                    "You should indent in by one level only"
                );
        } else
            $this->_level = 0;

        $levels = $this->_level - $oldLevel;

        //Unchanged levels
        if (!empty($indent) && $levels === 0)
            return;

        //We create a token for each indentation/outdentation
        $type = $levels > 0 ? 'indent' : 'outdent';
        $levels = abs($levels);

        while ($levels--)
            yield $this->createToken($type);
    }

    /**
     * Scans for a new-line character and yields a <newLine>-token if found
     *
     * @return \Generator
     */
    protected function scanNewLine()
    {

        foreach ($this->scanToken('newLine', "\n") as $token) {

            $this->_line++;
            $this->_offset = 0;
            yield $token;
        }
    }

    /**
     * Scans for text until the end of the current line
     * and yields a <text>-token if found
     *
     * @return \Generator
     */
    protected function scanText()
    {

        foreach ($this->scanToken('text', "([^\n]*)") as $token) {

            $value = trim($this->getMatch(1));

            if (empty($value))
                continue;

            $token['value'] = $value;
            yield $token;
        }
    }


    /**
     * Scans for text and keeps scanning text, if you indent once
     * until it is outdented again (e.g. .-text-blocks, expressions, comments)
     *
     * Yields anything between <text>, <newLine>, <indent> and <outdent> tokens
     * it encounters
     *
     * @return \Generator
     */
    protected function scanTextBlock()
    {

        foreach ($this->scanText() as $token)
            yield $token;

        foreach ($this->scanFor(['newLine', 'indent']) as $token) {

            yield $token;

            if ($token['type'] === 'indent') {

                $level = 1;
                foreach ($this->scanFor(['indent', 'newLine', 'text']) as $subToken) {

                    yield $subToken;

                    if ($subToken['type'] === 'indent')
                        $level++;

                    if ($subToken['type'] === 'outdent') {

                        $level--;

                        if ($level <= 0)
                            break 2;
                    }
                }
            }
        }
    }

    /**
     * Scans for a |-style text-line and yields it along
     * with a text-block, if it has any
     *
     * @return \Generator
     */
    protected function scanTextLine()
    {

        if ($this->peek() !== '|')
            return;

        $this->consume();
        foreach ($this->scanTextBlock() as $token)
            yield $token;
    }

    /**
     * Scans for HTML-markup based on a starting <
     * The whole markup will be kept and yielded
     * as a <text>-token
     *
     * @return \Generator
     */
    protected function scanMarkup()
    {

        if ($this->peek() !== '<')
            return;

        foreach ($this->scanText() as $token)
            yield $token;
    }

    /**
     * Scans for //-? comments yielding a <comment>
     * token if found as well as a stack of text-block tokens
     *
     * @return \Generator
     */
    protected function scanComment()
    {

        if (!$this->match("\\/\\/(-)?[\t ]*"))
            return;

        $this->consumeMatch();

        $token = $this->createToken('comment');
        $token['rendered'] = $this->getMatch(1) ? false : true;

        yield $token;

        foreach ($this->scanTextBlock() as $token)
            yield $token;
    }

    /**
     * Scans for :<filterName>-style filters and yields
     * a <filter> token if found
     *
     * Filter-tokens always have:
     * name, which is the name of the filter
     *
     * @return \Generator
     */
    protected function scanFilter()
    {

        foreach ($this->scanToken('filter', ':(?<name>[a-zA-Z_][a-zA-Z0-9\-_]*)') as $token) {

            yield $token;

            foreach ($this->scanTextBlock() as $subToken)
                yield $subToken;
        }
    }

    /**
     * Scans for imports and yields an <import>-token if found
     *
     * Import-tokens always have:
     * importType, which is either "extends" or "include
     * path, the (relative) path to which the import points
     *
     * Import-tokens may have:
     * filter, which is an optional filter that should be only
     *         usable on "include"
     *
     * @return \Generator
     */
    protected function scanImport()
    {

        return $this->scanToken(
            'import',
            '(?<importType>extends|include)(?::(?<filter>[a-zA-Z_][a-zA-Z0-9\-_]*))?[\t ]+(?<path>[a-zA-Z0-9\-_\\/\. ]+)'
        );
    }

    /**
     * Scans for <block>-tokens
     *
     * Blocks can have three styles:
     * block append|prepend|replace name
     * append|prepend|replace name
     * or simply
     * block (for mixin blocks)
     *
     * Block-tokens may have:
     * mode, which is either "append", "prepend" or "replace"
     * name, which is the name of the block
     *
     * @return \Generator
     */
    protected function scanBlock()
    {

        foreach ($this->scanToken(
            'block',
            'block(?:[\t ]+(?<mode>append|prepend|replace))?(?:[\t ]+(?<name>[a-zA-Z_][a-zA-Z0-9\-_]*))?'
        ) as $token) {

            yield $token;

            //Allow direct content via <sub> token (should do <indent> in the parser)
            foreach ($this->scanSub() as $subToken)
                yield $subToken;
        }

        foreach ($this->scanToken(
            'block',
            '(?<mode>append|prepend|replace)(?:[\t ]+(?<name>[a-zA-ZA-Z][a-zA-Z0-9\-_]*))'
        ) as $token) {

            yield $token;

            foreach ($this->scanSub() as $subToken)
                yield $subToken;
        }
    }

    /**
     * Scans for a <case>-token
     *
     * Case-tokens always have:
     * subject, which is the expression between the parenthesis
     *
     * @return \Generator
     */
    protected function scanCase()
    {

        return $this->scanControlStatement('case', ['case']);
    }

    /**
     * Scans for a <when>-token
     *
     * When-tokens always have:
     * name, which is either "when" or "default"
     * subject, which is the expression behind "when ..."
     *
     * When-tokens may have:
     * default, which indicates that this is the "default"-case
     *
     * @return \Generator
     */
    protected function scanWhen()
    {

        foreach ($this->scanControlStatement('when', ['when', 'default'], 'name') as $token) {

            if ($token['type'] === 'when')
                $token['default'] = ($token['name'] === 'default');

            yield $token;
        }
    }

    /**
     * Scans for a <conditional>-token
     *
     * Conditional-tokens always have:
     * conditionType, which is either "if", "unless", "elseif", "else if" or "else"
     * subject, which is the expression the between the parenthesis
     *
     * @return \Generator
     */
    protected function scanConditional()
    {

        return $this->scanControlStatement('conditional', [
            'if', 'unless', 'elseif', 'else if', 'else'
        ], 'conditionType');
    }

    /**
     * Scans for a control-statement-kind of token
     *
     * e.g.
     * control-statement-name ($expression)
     *
     * Since the <each>-statement is a special little unicorn, it
     * get's handled very specifically inside this function (But correctly!)
     *
     * If the condition can have a subject, the subject
     * will be set as the "subject"-value of the token
     *
     * @todo Avoid block parsing on <do>-loops
     * @param string $type The token type that should be created if scan is successful
     * @param array $names The names the statement can have (e.g. do, while, if, else etc.)
     * @param string|null $nameAttribute The attribute the name gets saved into, if wanted
     *
     * @return \Generator
     * @throws \Tale\Jade\Lexer\Exception
     */
    protected function scanControlStatement($type, array $names, $nameAttribute = null)
    {

        foreach ($names as $name) {

            if (!$this->match("{$name}[:\t \n]"))
                continue;

            $this->consumeMatch();
            $this->readSpaces();

            $token = $this->createToken($type);
            if ($nameAttribute)
                $token[$nameAttribute] = str_replace(' ', '', $name);
            $token['subject'] = null;

            //each is a special little unicorn
            if ($name === 'each') {

                if (!$this->match('\$?(?<itemName>[a-zA-Z_][a-zA-Z0-9_]*)(?:[\t ]*,[\t ]*\$?(?<keyName>[a-zA-Z_][a-zA-Z0-9_]*))?[\t ]+in[\t ]+'))
                    $this->throwException(
                        "The syntax for each is `each [$]itemName[, [$]keyName] in [subject]`",
                        $token
                    );

                $this->consumeMatch();
                $token['itemName'] = $this->getMatch('itemName');
                $token['keyName'] = $this->getMatch('keyName');
                $this->readSpaces();
            }

            if ($this->peek() === '(') {

                $this->consume();
                $token['subject'] = $this->readBracketContents();

                if ($this->peek() !== ')')
                    $this->throwException(
                        "Unclosed control statement subject"
                    );

                $this->consume();
            } elseif ($this->match("([^:\n]+)")){

                $this->consumeMatch();
                $token['subject'] = trim($this->getMatch(1));
            }

            yield $token;

            foreach ($this->scanSub() as $subToken)
                yield $subToken;
        }
    }

    /**
     * Scans for an <each>-token
     *
     * Each-tokens always have:
     * itemName, which is the name of the item for each iteration
     * subject, which is the expression to iterate
     *
     * Each-tokens may have:
     * keyName, which is the name of the key for each iteration
     *
     * @return \Generator
     */
    protected function scanEach()
    {

        return $this->scanControlStatement('each', ['each']);
    }

    /**
     * Scans for a <while>-token
     *
     * While-tokens always have:
     * subject, which is the expression between the parenthesis
     *
     * @return \Generator
     */
    protected function scanWhile()
    {

        return $this->scanControlStatement('while', ['while']);
    }

    /**
     * Scans for a <do>-token
     *
     * Do-tokens are always stand-alone
     *
     * @return \Generator
     */
    protected function scanDo()
    {

        return $this->scanControlStatement('do', ['do']);
    }

    /**
     * Scans for a - or !?=-style expression
     *
     * e.g.
     * != expr
     * = expr
     * - expr
     *      multiline
     *      expr
     *
     * Expression-tokens always have:
     * escaped, which indicates that the expression result should be escaped
     * return, which indicates if the expression should return or just evaluate the result
     *
     * @return \Generator
     */
    protected function scanExpression()
    {

        if ($this->peek() === '-') {

            $this->consume();
            $token = $this->createToken('expression');
            $token['escaped'] = false;
            $token['return'] = false;
            yield $token;
            $this->readSpaces();

            foreach ($this->scanTextBlock() as $subToken)
                yield $subToken;
        }

        foreach ($this->scanToken(
            'expression',
            "([!]?[=])[\t ]*"
        ) as $token) {

            $token['escaped'] = $this->getMatch(1) === '!=' ? false : true;
            $token['return'] = true;
            yield $token;

            foreach ($this->scanText() as $subToken)
                yield $subToken;
        }
    }

    /**
     * Scans for a <expansion>-token
     * (a: b-style expansion or a:b-style tags)
     *
     * Expansion-tokens always have:
     * withSpace, which indicates wether there's a space after the double-colon
     *
     * Usually, if there's no space, it should be handled as part of a tag-name
     *
     * @return \Generator
     */
    protected function scanExpansion()
    {

        if ($this->peek() === ':') {

            $this->consume();
            $token = $this->createToken('expansion');

            $spaces = $this->readSpaces();
            $token['withSpace'] = !empty($spaces);

            yield $token;
        }
    }

    /**
     * Scans sub-expressions of elements, e.g. a text-block
     * initiated with a dot (.) or a block expansion
     *
     * Yields whatever scanTextBlock() and scanExpansion() yield
     *
     * @return \Generator
     */
    protected function scanSub()
    {

        if ($this->peek() === '.') {

            $this->consume();
            foreach ($this->scanTextBlock() as $token)
                yield $token;
        }

        foreach ($this->scanExpansion() as $token)
            yield $token;
    }

    /**
     * Scans for a <doctype>-token
     *
     * Doctype-tokens always have:
     * name, which is the passed name of the doctype or a custom-doctype,
     *       if the named doctype isn't provided
     *
     * @return \Generator
     */
    protected function scanDoctype()
    {

        return $this->scanToken('doctype', "(doctype|!!!) (?<name>[^\n]*)");
    }

    /**
     * Scans for a <tag>-token
     *
     * Tag-tokens always have:
     * name, which is the name of the tag
     *
     * @return \Generator
     */
    protected function scanTag()
    {

        foreach ($this->scanToken('tag', '(?<name>[a-zA-Z_][a-zA-Z0-9\-_]*)', 'i') as $token) {

            yield $token;

            //Make sure classes are scanned on this before we scan the . add-on
            foreach ($this->scanClasses() as $subToken)
                yield $subToken;

            foreach ($this->scanSub() as $subToken)
                yield $subToken;
        }
    }

    /**
     * Scans for a <class>-token (begins with dot (.))
     *
     * Class-tokens always have:
     * name, which is the name of the class
     *
     * @return \Generator
     */
    protected function scanClasses()
    {

        foreach ($this->scanToken('class', '(\.(?<name>[a-zA-Z_][a-zA-Z0-9\-_]*))', 'i') as $token) {

            yield $token;

            //Make sure classes are scanned on this before we scan the . add-on
            foreach ($this->scanClasses() as $subToken)
                yield $subToken;

            foreach ($this->scanSub() as $subToken)
                yield $subToken;
        }
    }

    /**
     * Scans for a <id>-token (begins with hash (#))
     *
     * ID-tokens always have:
     * name, which is the name of the id
     *
     * @return \Generator
     */
    protected function scanId()
    {

        foreach ($this->scanToken('id', '(#(?<name>[a-zA-Z_][a-zA-Z0-9\-_]*))', 'i') as $token) {

            yield $token;

            //Make sure classes are scanned on this before we scan the . add-on
            foreach ($this->scanClasses() as $subToken)
                yield $subToken;

            foreach ($this->scanSub() as $subToken)
                yield $subToken;
        }
    }

    /**
     * Scans for a mixin definition token (<mixin>)
     *
     * Mixin-token always have:
     * name, which is the name of the mixin you want to define
     *
     * @return \Generator
     */
    protected function scanMixin()
    {

        foreach ($this->scanToken('mixin', "mixin[\t ]+(?<name>[a-zA-Z_][a-zA-Z0-9\-_]*)") as $token) {

            yield $token;

            //Make sure classes are scanned on this before we scan the . add-on
            foreach ($this->scanClasses() as $subToken)
                yield $subToken;

            foreach ($this->scanSub() as $subToken)
                yield $subToken;
        }
    }

    /**
     * Scans for a <mixinCall>-token (begins with plus (+))
     *
     * Mixin-Call-Tokens always have:
     * name, which is the name of the called mixin
     *
     * @return \Generator
     */
    protected function scanMixinCall()
    {

        foreach ($this->scanToken('mixinCall', '\+(?<name>[a-zA-Z_][a-zA-Z0-9\-_]*)') as $token) {

            yield $token;

            //Make sure classes are scanned on this before we scan the . add-on
            foreach ($this->scanClasses() as $subToken)
                yield $subToken;

            foreach ($this->scanSub() as $subToken)
                yield $subToken;
        }
    }

    /**
     * Scans for an <assignment>-token (begins with ampersand (&))
     *
     * Assignment-Tokens always have:
     * name, which is the name of the assignment
     *
     * @return \Generator
     */
    protected function scanAssignment()
    {

        foreach ($this->scanToken('assignment', '&(?<name>[a-zA-Z_][a-zA-Z0-9\-_]*)') as $token) {

            yield $token;
        }
    }

    /**
     * Scans for an attribute-block
     * Attribute blocks always consist of the following tokens:
     *
     * <attributeStart> ('(') -> Indicates that attributes start here
     * <attribute>... (name*=*value*) -> Name and Value are both optional, but one of both needs to be provided
     *                                   Multiple attributes are separated by a Comma (,)
     * <attributeEnd> (')') -> Required. Indicates the end of the attribute block
     *
     * This function will always yield an <attributeStart>-token first, if there's an attribute block
     * Attribute-blocks can be split across multiple lines and don't respect indentation of any kind
     * except for the <attributeStart> token
     *
     * After that it will continue to yield <attribute>-tokens containing
     *  > name, which is the name of the attribute (Default: null)
     *  > value, which is the value of the attribute (Default: null)
     *  > escaped, which indicates that the attribute expression result should be escaped
     *
     * After that it will always require and yield an <attributeEnd> token
     *
     * If the <attributeEnd> is not found, this function will throw an exception
     *
     * Between <attributeStart>, <attribute>, and <attributeEnd>
     * as well as around = and , of the attributes you can utilize as many
     * spaces and new-lines as you like
     *
     * @return \Generator
     * @throws \Tale\Jade\Lexer\Exception
     */
    protected function scanAttributes()
    {

        if ($this->peek() !== '(')
            return;

        $this->consume();
        yield $this->createToken('attributeStart');
        $this->read('ctype_space');

        if ($this->peek() !== ')') {

            $continue = true;
            while(!$this->isAtEnd() && $continue) {

                $token = $this->createToken('attribute');
                $token['name'] = null;
                $token['value'] = null;
                $token['escaped'] = true;

                if ($this->match('((\.\.\.)?[a-zA-Z_][a-zA-Z0-9\-_]*)', 'i')) {

                    $this->consumeMatch();
                    $token['name'] = $this->getMatch(1);
                    $this->read('ctype_space');
                }

                if ($this->peek() === '!') {

                    $token['escaped'] = false;
                    $this->consume();
                }

                if (!$token['name'] || $this->peek() === '=') {

                    if ($token['name']) {

                        $this->consume();
                        $this->read('ctype_space');
                    }

                    $token['value'] = $this->readBracketContents([',']);
                }

                if ($this->peek() === ',') {

                    $this->consume();
                    $this->read('ctype_space');
                    $continue = true;
                } else {

                    $continue = false;
                }

                yield $token;
            }
        }

        if ($this->peek() !== ')')
            $this->throwException(
                "Unclosed attribute block"
            );

        $this->consume();
        yield $this->createToken('attributeEnd');

        //Make sure classes are scanned on this before we scan the . add-on
        foreach ($this->scanClasses() as $token)
            yield $token;

        foreach($this->scanSub() as $token)
            yield $token;
    }

    /**
     * Throws a lexer-exception
     *
     * The current line and offset of the exception
     * get automatically appended to the message
     *
     * @param string $message A meaningful error message
     *
     * @throws \Tale\Jade\Lexer\Exception
     */
    protected function throwException($message)
    {

        $message = "Failed to parse jade: $message (Line: {$this->_line}, Offset: {$this->_offset})";
        throw new Exception($message);
    }

    /**
     * mb_* compatible version of PHP's strlen
     * (so we don't require mb.func_overload)
     *
     * @see strlen
     * @param string $string The string to get the length of
     *
     * @return int The multi-byte-respecting length of the string
     */
    protected function strlen($string)
    {

        if (function_exists('mb_strlen'))
            return mb_strlen($string, $this->_options['encoding']);

        return strlen($string);
    }

    /**
     * mb_* compatible version of PHP's strpos
     * (so we don't require mb.func_overload)
     *
     * @see strpos
     * @param string $haystack The string to search in
     * @param string $needle The string we search for
     * @param int|null $offset The offset at which we might expect it
     *
     * @return int|false The offset of the string or false, if not found
     */
    protected function strpos($haystack, $needle, $offset = null)
    {

        if (function_exists('mb_strpos'))
            return mb_strpos($haystack, $needle, $offset, $this->_options['encoding']);

        return strpos($haystack, $needle, $offset);
    }

    /**
     * mb_* compatible version of PHP's substr
     * (so we don't require mb.func_overload)
     *
     * @see substr
     * @param string $string The string to get a sub-string of
     * @param int $start The start-index
     * @param int|null $range The amount of characters we want to get
     *
     * @return string The sub-string
     */
    protected function substr($string, $start, $range = null)
    {

        if (function_exists('mb_substr'))
            return mb_substr($string, $start, $range, $this->_options['encoding']);

        return substr($string, $start, $range);
    }

    /**
     * mb_* compatible version of PHP's substr_count
     * (so we don't require mb.func_overload)
     *
     * @param string $haystack The string we want to count sub-strings in
     * @param string $needle The sub-string we want to count inside $haystack
     *
     * @return int The amount of occurences of $needle in $haystack
     */
    protected function substr_count($haystack, $needle)
    {
        if (function_exists('mb_substr_count'))
            return mb_substr_count($haystack, $needle, $this->_options['encoding']);

        return substr_count($haystack, $needle);
    }
}