<?php declare (strict_types = 1);

/*
 * This file is part of the BibTex Parser.
 *
 * (c) Renan de Lima Barbosa <renandelima@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RenanBr\BibTexParser\Test;

use RenanBr\BibTexParser\Parser;
use RenanBr\BibTexParser\ParseException;

class ParserTest extends \PHPUnit_Framework_TestCase
{
    public function testBasic()
    {
        $listener = new DummyListener;

        $parser = new Parser;
        $parser->addListener($listener);
        $parser->parseFile(__DIR__ . '/resources/basic.bib');

        $expected = [
            [Parser::TYPE, 'basic'],
            [Parser::KEY, 'foo'],
            [Parser::RAW_VALUE, 'bar'],
        ];

        $this->assertEquals($expected, $listener->calls);
    }

    public function testKeyWithoutValue()
    {
        $listener = new DummyListener;

        $parser = new Parser;
        $parser->addListener($listener);
        $parser->parseFile(__DIR__ . '/resources/no-value.bib');

        $expected = [
            [Parser::TYPE, 'noValue'],
            [Parser::KEY, 'foo'],
            [Parser::KEY, 'bar'],
        ];

        $this->assertEquals($expected, $listener->calls);
    }

    public function testValueReading()
    {
        $listener = new DummyListener;

        $parser = new Parser;
        $parser->addListener($listener);
        $parser->parseFile(__DIR__ . '/resources/values-basic.bib');

        $expected = [
            [Parser::TYPE, 'valuesBasic'],
            [Parser::KEY, 'kNull'],
            [Parser::KEY, 'kStillNull'],
            [Parser::KEY, 'kRaw'],
            [Parser::RAW_VALUE, 'raw'],
            [Parser::KEY, 'kBraced'],
            [Parser::BRACED_VALUE, ' braced value '],
            [Parser::KEY, 'kBracedEmpty'],
            [Parser::BRACED_VALUE, ''],
            [Parser::KEY, 'kQuoted'],
            [Parser::QUOTED_VALUE, ' quoted value '],
            [Parser::KEY, 'kQuotedEmpty'],
            [Parser::QUOTED_VALUE, ''],
        ];

        $this->assertEquals($expected, $listener->calls);
    }

    public function testValueScaping()
    {
        $listener = new DummyListener;

        $parser = new Parser;
        $parser->addListener($listener);
        $parser->parseFile(__DIR__ . '/resources/values-escaped.bib');

        $expected = [
            [Parser::TYPE, 'valuesEscaped'],
            [Parser::KEY, 'braced'],
            [Parser::BRACED_VALUE, 'the } " \\ % braced'],
            [Parser::KEY, 'quoted'],
            [Parser::QUOTED_VALUE, 'the } " \\ % quoted'],
        ];

        $this->assertEquals($expected, $listener->calls);
    }

    public function testMultipleValues()
    {
        $listener = new DummyListener;

        $parser = new Parser;
        $parser->addListener($listener);
        $parser->parseFile(__DIR__ . '/resources/values-multiple.bib');

        $expected = [
            [Parser::TYPE, 'multipleValues'],
            [Parser::KEY, 'raw'],
            [Parser::RAW_VALUE, 'rawA'],
            [Parser::RAW_VALUE, 'rawB'],
            [Parser::KEY, 'quoted'],
            [Parser::QUOTED_VALUE, 'quoted a'],
            [Parser::QUOTED_VALUE, 'quoted b'],
            [Parser::KEY, 'braced'],
            [Parser::BRACED_VALUE, 'braced a'],
            [Parser::BRACED_VALUE, 'braced b'],
            [Parser::KEY, 'misc'],
            [Parser::QUOTED_VALUE, 'quoted'],
            [Parser::BRACED_VALUE, 'braced'],
            [Parser::RAW_VALUE, 'raw'],
            [Parser::KEY, 'noSpace'],
            [Parser::RAW_VALUE, 'raw'],
            [Parser::QUOTED_VALUE, 'quoted'],
            [Parser::BRACED_VALUE, 'braced'],
        ];

        $this->assertEquals($expected, $listener->calls);
    }

    public function testCommentIgnoring()
    {
        $listener = new DummyListener;

        $parser = new Parser;
        $parser->addListener($listener);
        $parser->parseFile(__DIR__ . '/resources/comment.bib');

        $expected = [
            [Parser::TYPE, 'comment'],
            [Parser::KEY, 'key'],
            [Parser::RAW_VALUE, 'value'],
            [Parser::KEY, 'still'],
            [Parser::RAW_VALUE, 'here'],
            [Parser::KEY, 'insideQuoted'],
            [Parser::QUOTED_VALUE, 'before--after'],
            [Parser::KEY, 'commentAfterKey'],
            [Parser::RAW_VALUE, 'commentAfterRaw'],
        ];

        $this->assertEquals($expected, $listener->calls);
    }

    public function testValueSlashes()
    {
        $listener = new DummyListener;

        $parser = new Parser;
        $parser->addListener($listener);
        $parser->parseFile(__DIR__ . '/resources/values-slashes.bib');

        $expected = [
            [Parser::TYPE, 'valuesSlashes'],
            [Parser::KEY, 'braced'],
            [Parser::BRACED_VALUE, '\\}\\"\\%\\'],
            [Parser::KEY, 'quoted'],
            [Parser::QUOTED_VALUE, '\\}\\"\\%\\'],
        ];

        $this->assertEquals($expected, $listener->calls);
    }

    public function testValueNestedBraces()
    {
        $listener = new DummyListener;

        $parser = new Parser;
        $parser->addListener($listener);
        $parser->parseFile(__DIR__ . '/resources/values-nested-braces.bib');

        $expected = [
            [Parser::TYPE, 'valuesBraces'],
            [Parser::KEY, 'link'],
            [Parser::BRACED_VALUE, '\url{https://github.com}'],
            [Parser::KEY, 'twoLevels'],
            [Parser::BRACED_VALUE, 'a{b{c}d}e'],
            [Parser::KEY, 'escapedBrace'],
            [Parser::BRACED_VALUE, 'before{}}after'],
        ];

        $this->assertEquals($expected, $listener->calls);
    }

    public function testFileDoesNotExist()
    {
        $parser = new Parser;

        $this->expectException(\PHPUnit_Framework_Error_Warning::class);
        $parser->parseFile(__DIR__ . '/resources/does-not-exist');
    }

    /**
     * @dataProvider invalidProvider
     */
    public function testInvalid(string $file, string $message)
    {
        $parser = new Parser;

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage($message);
        $parser->parseFile($file);
    }

    public function invalidProvider(): array
    {
        $dir = __DIR__ . '/resources/invalid';
        return [
            [$dir . '/brace-missing.bib', "'\\0' at line 3 column 1"],
            [$dir . '/multiple-braced-values.bib', "'{' at line 2 column 33"],
            [$dir . '/multiple-quoted-values.bib', "'\"' at line 2 column 33"],
            [$dir . '/multiple-raw-values.bib', "'b' at line 2 column 31"],
            [$dir . '/space-after-at-sign.bib', "' ' at line 1 column 2"],
            [$dir . '/splitted-key.bib', "'k' at line 2 column 14"],
            [$dir . '/splitted-type.bib', "'T' at line 1 column 11"],
            [$dir . '/no-comment.bib', "'i' at line 1 column 1"],
            [$dir . '/double-concat.bib', "'#' at line 2 column 20"],
        ];
    }

    public function testStringParser()
    {
        $listener = new DummyListener;

        $parser = new Parser;
        $parser->addListener($listener);
        $parser->parseString(file_get_contents(__DIR__ . '/resources/basic.bib'));

        $expected = [
            [Parser::TYPE, 'basic'],
            [Parser::KEY, 'foo'],
            [Parser::RAW_VALUE, 'bar'],
        ];

        $this->assertEquals($expected, $listener->calls);
    }

    public function testBasicOffsetContext()
    {
        $listener = new DummyListener;

        $parser = new Parser;
        $parser->addListener($listener);
        $parser->parseFile(__DIR__ . '/resources/basic.bib');

        $expected = [
            ['state' => Parser::TYPE, 'offset' => 1, 'length' => 5],
            ['state' => Parser::KEY, 'offset' => 13, 'length' => 3],
            ['state' => Parser::RAW_VALUE, 'offset' => 19, 'length' => 3],
        ];

        $contexts = $listener->filterContexts(['state', 'offset', 'length']);

        $this->assertEquals($expected, $contexts);
    }

    public function testOffsetContextWithEscapedChar()
    {
        $listener = new DummyListener;

        $parser = new Parser;
        $parser->addListener($listener);

        // This file is interesting because the values have escaped chars, which
        // means the value length in the file is not equal to the triggered one
        $parser->parseFile(__DIR__ . '/resources/values-escaped.bib');

        $expected = [
            ['state' => Parser::TYPE, 'offset' => 1, 'length' => 13],
            ['state' => Parser::KEY, 'offset' => 21, 'length' => 6],
            ['state' => Parser::BRACED_VALUE, 'offset' => 31, 'length' => 21],
            ['state' => Parser::KEY, 'offset' => 59, 'length' => 6],
            ['state' => Parser::QUOTED_VALUE, 'offset' => 69, 'length' => 21],
        ];

        $contexts = $listener->filterContexts(['state', 'offset', 'length']);

        $this->assertEquals($expected, $contexts);
    }
}
