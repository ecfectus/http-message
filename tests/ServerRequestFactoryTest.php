<?php

namespace Conformity\Http\Message;

use PHPUnit_Framework_TestCase as TestCase;
use ReflectionProperty;
use Conformity\Http\Message\ServerRequest;
use Conformity\Http\Message\ServerRequestFactory;
use Conformity\Http\Message\UploadedFile;
use Conformity\Http\Message\Uri;

class ServerRequestFactoryTest extends TestCase
{
    public function testGetWillReturnValueIfPresentInArray()
    {
        $array = [
            'foo' => 'bar',
            'bar' => '',
            'baz' => null,
        ];

        foreach ($array as $key => $value) {
            $this->assertSame($value, ServerRequestFactory::get($key, $array));
        }
    }

    public function testGetWillReturnDefaultValueIfKeyIsNotInArray()
    {
        $try   = [ 'foo', 'bar', 'baz' ];
        $array = [
            'quz'  => true,
            'quuz' => true,
        ];
        $default = 'BAT';

        foreach ($try as $key) {
            $this->assertSame($default, ServerRequestFactory::get($key, $array, $default));
        }
    }

    public function testReturnsServerValueUnchangedIfHttpAuthorizationHeaderIsPresent()
    {
        $server = [
            'HTTP_AUTHORIZATION' => 'token',
            'HTTP_X_Foo' => 'bar',
        ];
        $this->assertSame($server, ServerRequestFactory::normalizeServer($server));
    }

    public function testMarshalsExpectedHeadersFromServerArray()
    {
        $server = [
            'HTTP_COOKIE' => 'COOKIE',
            'HTTP_AUTHORIZATION' => 'token',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_FOO_BAR' => 'FOOBAR',
            'CONTENT_MD5' => 'CONTENT-MD5',
            'CONTENT_LENGTH' => 'UNSPECIFIED',
        ];

        $expected = [
            'authorization' => 'token',
            'content-type' => 'application/json',
            'accept' => 'application/json',
            'x-foo-bar' => 'FOOBAR',
            'content-md5' => 'CONTENT-MD5',
            'content-length' => 'UNSPECIFIED',
        ];

        $this->assertEquals($expected, ServerRequestFactory::marshalHeaders($server));
    }

    public function testStripQueryStringReturnsUnchangedStringIfNoQueryStringDetected()
    {
        $path = '/foo/bar';
        $this->assertSame($path, ServerRequestFactory::stripQueryString($path));
    }

    public function testStripQueryStringReturnsNormalizedPathWhenQueryStringDetected()
    {
        $path = '/foo/bar?foo=bar';
        $this->assertSame('/foo/bar', ServerRequestFactory::stripQueryString($path));
    }

    public function testMarshalRequestUriUsesIISUnencodedUrlValueIfPresentAndUrlWasRewritten()
    {
        $server = [
            'IIS_WasUrlRewritten' => '1',
            'UNENCODED_URL' => '/foo/bar',
        ];

        $this->assertEquals($server['UNENCODED_URL'], ServerRequestFactory::marshalRequestUri($server));
    }

    public function testMarshalRequestUriUsesHTTPXRewriteUrlIfPresent()
    {
        $server = [
            'IIS_WasUrlRewritten' => null,
            'UNENCODED_URL' => '/foo/bar',
            'REQUEST_URI' => '/overridden',
            'HTTP_X_REWRITE_URL' => '/bar/baz',
        ];

        $this->assertEquals($server['HTTP_X_REWRITE_URL'], ServerRequestFactory::marshalRequestUri($server));
    }

    public function testMarshalRequestUriUsesHTTPXOriginalUrlIfPresent()
    {
        $server = [
            'IIS_WasUrlRewritten' => null,
            'UNENCODED_URL' => '/foo/bar',
            'REQUEST_URI' => '/overridden',
            'HTTP_X_REWRITE_URL' => '/bar/baz',
            'HTTP_X_ORIGINAL_URL' => '/baz/bat',
        ];

        $this->assertEquals($server['HTTP_X_ORIGINAL_URL'], ServerRequestFactory::marshalRequestUri($server));
    }

    public function testMarshalRequestUriStripsSchemeHostAndPortInformationWhenPresent()
    {
        $server = [
            'REQUEST_URI' => 'http://example.com:8000/foo/bar',
        ];

        $this->assertEquals('/foo/bar', ServerRequestFactory::marshalRequestUri($server));
    }

    public function testMarshalRequestUriUsesOrigPathInfoIfPresent()
    {
        $server = [
            'ORIG_PATH_INFO' => '/foo/bar',
        ];

        $this->assertEquals('/foo/bar', ServerRequestFactory::marshalRequestUri($server));
    }

    public function testMarshalRequestUriFallsBackToRoot()
    {
        $server = [];

        $this->assertEquals('/', ServerRequestFactory::marshalRequestUri($server));
    }

    public function testMarshalHostAndPortUsesHostHeaderWhenPresent()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri('http://example.com/'));
        $request = $request->withMethod('GET');
        $request = $request->withHeader('Host', 'example.com');

        $accumulator = (object) ['host' => '', 'port' => null];
        ServerRequestFactory::marshalHostAndPortFromHeaders($accumulator, [], $request->getHeaders());
        $this->assertEquals('example.com', $accumulator->host);
        $this->assertNull($accumulator->port);
    }

    public function testMarshalHostAndPortWillDetectPortInHostHeaderWhenPresent()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri('http://example.com:8000/'));
        $request = $request->withMethod('GET');
        $request = $request->withHeader('Host', 'example.com:8000');

        $accumulator = (object) ['host' => '', 'port' => null];
        ServerRequestFactory::marshalHostAndPortFromHeaders($accumulator, [], $request->getHeaders());
        $this->assertEquals('example.com', $accumulator->host);
        $this->assertEquals(8000, $accumulator->port);
    }

    public function testMarshalHostAndPortReturnsEmptyValuesIfNoHostHeaderAndNoServerName()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri());

        $accumulator = (object) ['host' => '', 'port' => null];
        ServerRequestFactory::marshalHostAndPortFromHeaders($accumulator, [], $request->getHeaders());
        $this->assertEquals('', $accumulator->host);
        $this->assertNull($accumulator->port);
    }

    public function testMarshalHostAndPortReturnsServerNameForHostWhenPresent()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri('http://example.com/'));

        $server  = [
            'SERVER_NAME' => 'example.com',
        ];
        $accumulator = (object) ['host' => '', 'port' => null];
        ServerRequestFactory::marshalHostAndPortFromHeaders($accumulator, $server, $request->getHeaders());
        $this->assertEquals('example.com', $accumulator->host);
        $this->assertNull($accumulator->port);
    }

    public function testMarshalHostAndPortReturnsServerPortForPortWhenPresentWithServerName()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri());

        $server  = [
            'SERVER_NAME' => 'example.com',
            'SERVER_PORT' => 8000,
        ];
        $accumulator = (object) ['host' => '', 'port' => null];
        ServerRequestFactory::marshalHostAndPortFromHeaders($accumulator, $server, $request->getHeaders());
        $this->assertEquals('example.com', $accumulator->host);
        $this->assertEquals(8000, $accumulator->port);
    }

    public function testMarshalHostAndPortReturnsServerNameForHostIfServerAddrPresentButHostIsNotIpv6Address()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri('http://example.com/'));

        $server  = [
            'SERVER_ADDR' => '127.0.0.1',
            'SERVER_NAME' => 'example.com',
        ];
        $accumulator = (object) ['host' => '', 'port' => null];
        ServerRequestFactory::marshalHostAndPortFromHeaders($accumulator, $server, $request->getHeaders());
        $this->assertEquals('example.com', $accumulator->host);
    }

    public function testMarshalHostAndPortReturnsServerAddrForHostIfPresentAndHostIsIpv6Address()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri());

        $server  = [
            'SERVER_ADDR' => 'FE80::0202:B3FF:FE1E:8329',
            'SERVER_NAME' => '[FE80::0202:B3FF:FE1E:8329]',
            'SERVER_PORT' => 8000,
        ];
        $accumulator = (object) ['host' => '', 'port' => null];
        ServerRequestFactory::marshalHostAndPortFromHeaders($accumulator, $server, $request->getHeaders());
        $this->assertEquals('[FE80::0202:B3FF:FE1E:8329]', $accumulator->host);
        $this->assertEquals(8000, $accumulator->port);
    }

    public function testMarshalHostAndPortWillDetectPortInIpv6StyleHost()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri());

        $server  = [
            'SERVER_ADDR' => 'FE80::0202:B3FF:FE1E:8329',
            'SERVER_NAME' => '[FE80::0202:B3FF:FE1E:8329:80]',
        ];
        $accumulator = (object) ['host' => '', 'port' => null];
        ServerRequestFactory::marshalHostAndPortFromHeaders($accumulator, $server, $request->getHeaders());
        $this->assertEquals('[FE80::0202:B3FF:FE1E:8329]', $accumulator->host);
        $this->assertEquals(80, $accumulator->port);
    }

    public function testMarshalUriDetectsHttpsSchemeFromServerValue()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri('http://example.com/'));
        $request = $request->withHeader('Host', 'example.com');

        $server  = [
            'HTTPS' => true,
        ];

        $uri = ServerRequestFactory::marshalUriFromServer($server, $request->getHeaders());
        $this->assertInstanceOf('Conformity\Http\Message\Uri', $uri);
        $this->assertEquals('https', $uri->getScheme());
    }

    public function testMarshalUriUsesHttpSchemeIfHttpsServerValueEqualsOff()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri('http://example.com/'));
        $request = $request->withHeader('Host', 'example.com');

        $server  = [
            'HTTPS' => 'off',
        ];

        $uri = ServerRequestFactory::marshalUriFromServer($server, $request->getHeaders());
        $this->assertInstanceOf('Conformity\Http\Message\Uri', $uri);
        $this->assertEquals('http', $uri->getScheme());
    }

    public function testMarshalUriDetectsHttpsSchemeFromXForwardedProtoValue()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri('http://example.com/'));
        $request = $request->withHeader('Host', 'example.com');
        $request = $request->withHeader('X-Forwarded-Proto', 'https');

        $server  = [];

        $uri = ServerRequestFactory::marshalUriFromServer($server, $request->getHeaders());
        $this->assertInstanceOf('Conformity\Http\Message\Uri', $uri);
        $this->assertEquals('https', $uri->getScheme());
    }

    public function testMarshalUriStripsQueryStringFromRequestUri()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri('http://example.com/'));
        $request = $request->withHeader('Host', 'example.com');

        $server = [
            'REQUEST_URI' => '/foo/bar?foo=bar',
        ];

        $uri = ServerRequestFactory::marshalUriFromServer($server, $request->getHeaders());
        $this->assertInstanceOf('Conformity\Http\Message\Uri', $uri);
        $this->assertEquals('/foo/bar', $uri->getPath());
    }

    public function testMarshalUriInjectsQueryStringFromServer()
    {
        $request = new ServerRequest();
        $request = $request->withUri(new Uri('http://example.com/'));
        $request = $request->withHeader('Host', 'example.com');

        $server = [
            'REQUEST_URI' => '/foo/bar?foo=bar',
            'QUERY_STRING' => 'bar=baz',
        ];

        $uri = ServerRequestFactory::marshalUriFromServer($server, $request->getHeaders());
        $this->assertInstanceOf('Conformity\Http\Message\Uri', $uri);
        $this->assertEquals('bar=baz', $uri->getQuery());
    }

    public function testCanCreateServerRequestViaFromGlobalsMethod()
    {
        $server = [
            'SERVER_PROTOCOL' => '1.1',
            'HTTP_HOST' => 'example.com',
            'HTTP_ACCEPT' => 'application/json',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/foo/bar',
            'QUERY_STRING' => 'bar=baz',
        ];

        $cookies = $query = $body = $files = [
            'bar' => 'baz',
        ];

        $cookies['cookies'] = true;
        $query['query']     = true;
        $body['body']       = true;
        $files              = [ 'files' => [
            'tmp_name' => 'php://temp',
            'size'     => 0,
            'error'    => 0,
            'name'     => 'foo.bar',
            'type'     => 'text/plain',
        ]];
        $expectedFiles = [
            'files' => new UploadedFile('php://temp', 0, 0, 'foo.bar', 'text/plain')
        ];

        $request = ServerRequestFactory::fromGlobals($server, $query, $body, $cookies, $files);
        $this->assertInstanceOf('Conformity\Http\Message\ServerRequest', $request);
        $this->assertEquals($cookies, $request->getCookieParams());
        $this->assertEquals($query, $request->getQueryParams());
        $this->assertEquals($body, $request->getParsedBody());
        $this->assertEquals($expectedFiles, $request->getUploadedFiles());
        $this->assertEmpty($request->getAttributes());
    }

    public function testNormalizeServerUsesMixedCaseAuthorizationHeaderFromApacheWhenPresent()
    {
        $r = new ReflectionProperty('Conformity\Http\Message\ServerRequestFactory', 'apacheRequestHeaders');
        $r->setAccessible(true);
        $r->setValue(function () {
            return ['Authorization' => 'foobar'];
        });

        $server = ServerRequestFactory::normalizeServer([]);

        $this->assertArrayHasKey('HTTP_AUTHORIZATION', $server);
        $this->assertEquals('foobar', $server['HTTP_AUTHORIZATION']);
    }

    public function testNormalizeServerUsesLowerCaseAuthorizationHeaderFromApacheWhenPresent()
    {
        $r = new ReflectionProperty('Conformity\Http\Message\ServerRequestFactory', 'apacheRequestHeaders');
        $r->setAccessible(true);
        $r->setValue(function () {
            return ['authorization' => 'foobar'];
        });

        $server = ServerRequestFactory::normalizeServer([]);

        $this->assertArrayHasKey('HTTP_AUTHORIZATION', $server);
        $this->assertEquals('foobar', $server['HTTP_AUTHORIZATION']);
    }

    public function testNormalizeServerReturnsArrayUnalteredIfApacheHeadersDoNotContainAuthorization()
    {
        $r = new ReflectionProperty('Conformity\Http\Message\ServerRequestFactory', 'apacheRequestHeaders');
        $r->setAccessible(true);
        $r->setValue(function () {
            return [];
        });

        $expected = ['FOO_BAR' => 'BAZ'];
        $server = ServerRequestFactory::normalizeServer($expected);

        $this->assertEquals($expected, $server);
    }

    /**
     * @group 57
     * @group 56
     */
    public function testNormalizeFilesReturnsOnlyActualFilesWhenOriginalFilesContainsNestedAssociativeArrays()
    {
        $files = [ 'fooFiles' => [
            'tmp_name' => ['file' => 'php://temp'],
            'size'     => ['file' => 0],
            'error'    => ['file' => 0],
            'name'     => ['file' => 'foo.bar'],
            'type'     => ['file' => 'text/plain'],
        ]];

        $normalizedFiles = ServerRequestFactory::normalizeFiles($files);

        $this->assertCount(1, $normalizedFiles['fooFiles']);
    }
}
