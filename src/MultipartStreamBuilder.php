<?php

namespace Http\MultipartStream;

use Http\Discovery\StreamFactoryDiscovery;
use Http\Message\StreamFactory;
use Psr\Http\Message\StreamInterface;

/**
 * Build your own Multipart stream
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class MultipartStreamBuilder
{
    /**
     * @var StreamFactory
     */
    private $streamFactory;

    /**
     * @var string
     */
    private $boundary;

    /**
     * @var array array Element where each Element is an array with keys ['contents', 'headers', 'filename']
     */
    private $data;

    /**
     * @param StreamFactory|null $streamFactory
     */
    public function __construct(StreamFactory $streamFactory = null)
    {
        $this->streamFactory = $streamFactory ?: StreamFactoryDiscovery::find();
    }

    /**
     * @param string $name the formpost name
     * @param StreamInterface|resource $resource
     * @param array $options         {
     *
     *     @var array $headers additional headers ['header-name' => 'header-value']
     *     @var string $filename
     * }
     */
    public function addResource($name, $resource, array $options)
    {
        if (!$resource instanceof StreamInterface) {
            $resource = $this->streamFactory->createStream($resource);
        }

        // TODO validate options


        $this->prepareHaeders($name, $resource, $options['filename'], $options['headers']);
        $this->data[$name] = ['contents' => $resource, 'headers' => $options['headers'], 'filename' => $options['filename']];
    }

    /**
     * Get the headers needed before transferring the content of a POST file.
     */
    private function getHeaders(array $headers)
    {
        $str = '';
        foreach ($headers as $key => $value) {
            $str .= "{$key}: {$value}\r\n";
        }

        return $str;
    }

    /**
     * Build the stream.
     */
    public function build()
    {
        $streams = [];
        foreach ($this->data as $data) {
            // Add start and headers
            $streams[] = $this->streamFactory->createStream(
                "--{$this->getBoundary()}\r\n".
                $this->getHeaders($data['headers'])."\r\n\r\n"
            );

            $streams[] = $data['contents'];
            $streams[] .= $this->streamFactory->createStream('\r\n');
        }

        // append end
        $streams[] = $this->streamFactory->createStream("--{$this->getBoundary()}--\r\n");
    }

    /**
     * Add extra headers if they are missing
     *
     * @param string $name
     * @param StreamInterface $stream
     * @param string $filename
     * @param array &$headers
     */
    private function prepareHaeders($name, StreamInterface $stream, $filename, array &$headers)
    {
        // Set a default content-disposition header if one was no provided
        $disposition = $this->getHeader($headers, 'content-disposition');
        if (!$disposition) {
            $headers['Content-Disposition'] = ($filename === '0' || $filename)
                ? sprintf('form-data; name="%s"; filename="%s"',
                    $name,
                    basename($filename))
                : "form-data; name=\"{$name}\"";
        }

        // Set a default content-length header if one was no provided
        $length = $this->getHeader($headers, 'content-length');
        if (!$length) {
            if ($length = $stream->getSize()) {
                $headers['Content-Length'] = (string) $length;
            }
        }

        // Set a default Content-Type if one was not supplied
        $type = $this->getHeader($headers, 'content-type');
        if (!$type && ($filename === '0' || $filename)) {
            if ($type = MimetypeHelper::getMimetypeFromFilename($filename)) {
                $headers['Content-Type'] = $type;
            }
        }
    }

    /**
     * Get one header by its name.
     *
     * @param array $headers
     * @param $key
     *
     * @return mixed|null
     */
    private function getHeader(array $headers, $key)
    {
        $lowercaseHeader = strtolower($key);
        foreach ($headers as $k => $v) {
            if (strtolower($k) === $lowercaseHeader) {
                return $v;
            }
        }

        return;
    }

    /**
     * @return string
     */
    public function getBoundary()
    {
        if ($this->boundary === null) {
            $this->boundary = uniqid();
        }

        return $this->boundary;
    }

    /**
     * @param string $boundary
     *
     * @return MultipartStreamBuilder
     */
    public function setBoundary($boundary)
    {
        $this->boundary = $boundary;

        return $this;
    }
}