<?php

namespace Conformity\Http\Message\Test;

use PHPUnit_Framework_TestCase as TestCase;
use ReflectionProperty;
use Conformity\Http\Message\Stream;
use Conformity\Http\Message\UploadedFile;

class UploadedFileTest extends TestCase
{
    protected $tmpFile;

    public function setUp()
    {
        $this->tmpfile = null;
    }

    public function tearDown()
    {
        if (is_scalar($this->tmpFile) && file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    public function invalidStreams()
    {
        return [
            'null'         => [null],
            'true'         => [true],
            'false'        => [false],
            'int'          => [1],
            'float'        => [1.1],
            /* Have not figured out a valid way to test an invalid path yet; null byte injection
             * appears to get caught by fopen()
            'invalid-path' => [ ('WIN' === strtoupper(substr(PHP_OS, 0, 3))) ? '[:]' : 'foo' . chr(0) ],
             */
            'array'        => [['filename']],
            'object'       => [(object) ['filename']],
        ];
    }

    /**
     * @dataProvider invalidStreams
     */
    public function testRaisesExceptionOnInvalidStreamOrFile($streamOrFile)
    {
        $this->setExpectedException('InvalidArgumentException');
        new UploadedFile($streamOrFile, 0, UPLOAD_ERR_OK);
    }

    public function invalidSizes()
    {
        return [
            'null'   => [null],
            'true'   => [true],
            'false'  => [false],
            'float'  => [1.1],
            'string' => ['1'],
            'array'  => [[1]],
            'object' => [(object) [1]],
        ];
    }

    /**
     * @dataProvider invalidSizes
     */
    public function testRaisesExceptionOnInvalidSize($size)
    {
        $this->setExpectedException('InvalidArgumentException', 'size');
        new UploadedFile(fopen('php://temp', 'wb+'), $size, UPLOAD_ERR_OK);
    }

    public function invalidErrorStatuses()
    {
        return [
            'null'     => [null],
            'true'     => [true],
            'false'    => [false],
            'float'    => [1.1],
            'string'   => ['1'],
            'array'    => [[1]],
            'object'   => [(object) [1]],
            'negative' => [-1],
            'too-big'  => [9],
        ];
    }

    /**
     * @dataProvider invalidErrorStatuses
     */
    public function testRaisesExceptionOnInvalidErrorStatus($status)
    {
        $this->setExpectedException('InvalidArgumentException', 'status');
        new UploadedFile(fopen('php://temp', 'wb+'), 0, $status);
    }

    public function invalidFilenamesAndMediaTypes()
    {
        return [
            'true'   => [true],
            'false'  => [false],
            'int'    => [1],
            'float'  => [1.1],
            'array'  => [['string']],
            'object' => [(object) ['string']],
        ];
    }

    /**
     * @dataProvider invalidFilenamesAndMediaTypes
     */
    public function testRaisesExceptionOnInvalidClientFilename($filename)
    {
        $this->setExpectedException('InvalidArgumentException', 'filename');
        new UploadedFile(fopen('php://temp', 'wb+'), 0, UPLOAD_ERR_OK, $filename);
    }

    /**
     * @dataProvider invalidFilenamesAndMediaTypes
     */
    public function testRaisesExceptionOnInvalidClientMediaType($mediaType)
    {
        $this->setExpectedException('InvalidArgumentException', 'media type');
        new UploadedFile(fopen('php://temp', 'wb+'), 0, UPLOAD_ERR_OK, 'foobar.baz', $mediaType);
    }

    public function testGetStreamReturnsOriginalStreamObject()
    {
        $stream = new Stream('php://temp');
        $upload = new UploadedFile($stream, 0, UPLOAD_ERR_OK);
        $this->assertSame($stream, $upload->getStream());
    }

    public function testGetStreamReturnsWrappedPhpStream()
    {
        $stream = fopen('php://temp', 'wb+');
        $upload = new UploadedFile($stream, 0, UPLOAD_ERR_OK);
        $uploadStream = $upload->getStream()->detach();
        $this->assertSame($stream, $uploadStream);
    }

    public function testGetStreamReturnsStreamForFile()
    {
        $this->tmpFile = $stream = tempnam(sys_get_temp_dir(), 'diac');
        $upload = new UploadedFile($stream, 0, UPLOAD_ERR_OK);
        $uploadStream = $upload->getStream();
        $r = new ReflectionProperty($uploadStream, 'stream');
        $r->setAccessible(true);
        $this->assertSame($stream, $r->getValue($uploadStream));
    }

    public function testMovesFileToDesignatedPath()
    {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write('Foo bar!');
        $upload = new UploadedFile($stream, 0, UPLOAD_ERR_OK);

        $this->tmpFile = $to = tempnam(sys_get_temp_dir(), 'diac');
        $upload->moveTo($to);
        $this->assertTrue(file_exists($to));
        $contents = file_get_contents($to);
        $this->assertEquals($stream->__toString(), $contents);
    }

    public function invalidMovePaths()
    {
        return [
            'null'   => [null],
            'true'   => [true],
            'false'  => [false],
            'int'    => [1],
            'float'  => [1.1],
            'empty'  => [''],
            'array'  => [['filename']],
            'object' => [(object) ['filename']],
        ];
    }

    /**
     * @dataProvider invalidMovePaths
     */
    public function testMoveRaisesExceptionForInvalidPath($path)
    {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write('Foo bar!');
        $upload = new UploadedFile($stream, 0, UPLOAD_ERR_OK);

        $this->tmpFile = $path;
        $this->setExpectedException('InvalidArgumentException', 'path');
        $upload->moveTo($path);
    }

    public function testMoveCannotBeCalledMoreThanOnce()
    {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write('Foo bar!');
        $upload = new UploadedFile($stream, 0, UPLOAD_ERR_OK);

        $this->tmpFile = $to = tempnam(sys_get_temp_dir(), 'diac');
        $upload->moveTo($to);
        $this->assertTrue(file_exists($to));

        $this->setExpectedException('RuntimeException', 'moved');
        $upload->moveTo($to);
    }

    public function testCannotRetrieveStreamAfterMove()
    {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write('Foo bar!');
        $upload = new UploadedFile($stream, 0, UPLOAD_ERR_OK);

        $this->tmpFile = $to = tempnam(sys_get_temp_dir(), 'diac');
        $upload->moveTo($to);
        $this->assertTrue(file_exists($to));

        $this->setExpectedException('RuntimeException', 'moved');
        $upload->getStream();
    }

    public function nonOkErrorStatus()
    {
        return [
            'UPLOAD_ERR_INI_SIZE'   => [ UPLOAD_ERR_INI_SIZE ],
            'UPLOAD_ERR_FORM_SIZE'  => [ UPLOAD_ERR_FORM_SIZE ],
            'UPLOAD_ERR_PARTIAL'    => [ UPLOAD_ERR_PARTIAL ],
            'UPLOAD_ERR_NO_FILE'    => [ UPLOAD_ERR_NO_FILE ],
            'UPLOAD_ERR_NO_TMP_DIR' => [ UPLOAD_ERR_NO_TMP_DIR ],
            'UPLOAD_ERR_CANT_WRITE' => [ UPLOAD_ERR_CANT_WRITE ],
            'UPLOAD_ERR_EXTENSION'  => [ UPLOAD_ERR_EXTENSION ],
        ];
    }

    /**
     * @dataProvider nonOkErrorStatus
     * @group 60
     */
    public function testConstructorDoesNotRaiseExceptionForInvalidStreamWhenErrorStatusPresent($status)
    {
        $uploadedFile = new UploadedFile('not ok', 0, $status);
        $this->assertSame($status, $uploadedFile->getError());
    }

    /**
     * @dataProvider nonOkErrorStatus
     * @group 60
     */
    public function testMoveToRaisesExceptionWhenErrorStatusPresent($status)
    {
        $uploadedFile = new UploadedFile('not ok', 0, $status);
        $this->setExpectedException('RuntimeException', 'upload error');
        $uploadedFile->moveTo(__DIR__ . '/' . uniqid());
    }

    /**
     * @dataProvider nonOkErrorStatus
     * @group 60
     */
    public function testGetStreamRaisesExceptionWhenErrorStatusPresent($status)
    {
        $uploadedFile = new UploadedFile('not ok', 0, $status);
        $this->setExpectedException('RuntimeException', 'upload error');
        $stream = $uploadedFile->getStream();
    }

    /**
     * @group 82
     */
    public function testMoveToCreatesStreamIfOnlyAFilenameWasProvided()
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'DIA');

        $uploadedFile = new UploadedFile(__FILE__, 100, UPLOAD_ERR_OK, basename(__FILE__), 'text/plain');
        $uploadedFile->moveTo($this->tmpFile);

        $original = file_get_contents(__FILE__);
        $test     = file_get_contents($this->tmpFile);

        $this->assertEquals($original, $test);
    }
}
