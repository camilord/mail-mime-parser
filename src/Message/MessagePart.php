<?php
/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace ZBateson\MailMimeParser\Message;

use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\StreamWrapper;
use Psr\Http\Message\StreamInterface;
use ZBateson\MailMimeParser\MailMimeParser;
use SplObjectStorage;
use SplObserver;

/**
 * Implements IMessagePart with a few concrete methods that can have reasonable
 * defaults.
 *
 * @author Zaahid Bateson
 */
abstract class MessagePart implements IMessagePart
{
    /**
     * @var IMimePart parent part
     */
    protected $parent;

    /**
     * @var PartStreamContainer holds 'stream' and 'contentStream'.
     */
    protected $partStreamContainer;

    /**
     * @var string can be used to set an override for content's charset in cases
     *      where a user knows the charset on the content is not what it claims
     *      to be.
     */
    protected $charsetOverride;

    /**
     * @var bool set to true when a user attaches a stream manually, it's
     *      assumed to already be decoded or to have relevant transfer encoding
     *      decorators attached already.
     */
    protected $ignoreTransferEncoding;

    /**
     * SplObjectStorage attached observers
     */
    protected $observers;

    public function __construct(PartStreamContainer $streamContainer)
    {
        $this->partStreamContainer = $streamContainer;
        $this->observers = new SplObjectStorage();
    }

    public function attach(SplObserver $observer)
    {
        $this->observers->attach($observer);
    }

    public function detach(SplObserver $observer)
    {
        $this->observers->detach($observer);
    }

    public function notify()
    {
        foreach ($this->observers as $observer) {
            $observer->update($this);
        }
        if ($this->parent !== null) {
            $this->parent->notify();
        }
    }

    public function hasContent()
    {
        return $this->partStreamContainer->hasContent();
    }

    public function getFilename()
    {
        return null;
    }

    public function getResourceHandle()
    {
        return StreamWrapper::getResource($this->getStream());
    }

    public function getStream()
    {
        return $this->partStreamContainer->getStream();
    }

    public function setCharsetOverride($charsetOverride, $onlyIfNoCharset = false)
    {
        if (!$onlyIfNoCharset || $this->getCharset() === null) {
            $this->charsetOverride = $charsetOverride;
        }
    }

    public function getContentResourceHandle($charset = MailMimeParser::DEFAULT_CHARSET)
    {
        trigger_error("getContentResourceHandle is deprecated since version 1.2.1", E_USER_DEPRECATED);
        $stream = $this->getContentStream($charset);
        if ($stream !== null) {
            return StreamWrapper::getResource($stream);
        }
        return null;
    }

    public function getContentStream($charset = MailMimeParser::DEFAULT_CHARSET)
    {
        if ($this->hasContent()) {
            $tr = ($this->ignoreTransferEncoding) ? '' : $this->getContentTransferEncoding();
            $ch = ($this->charsetOverride !== null) ? $this->charsetOverride : $this->getCharset();
            return $this->partStreamContainer->getContentStream(
                $tr,
                $ch,
                $charset
            );
        }
        return null;
    }

    public function getBinaryContentStream()
    {
        if ($this->hasContent()) {
            $tr = ($this->ignoreTransferEncoding) ? '' : $this->getContentTransferEncoding();
            return $this->partStreamContainer->getBinaryContentStream($tr);
        }
        return null;
    }

    public function getBinaryContentResourceHandle()
    {
        $stream = $this->getBinaryContentStream();
        if ($stream !== null) {
            return StreamWrapper::getResource($stream);
        }
        return null;
    }

    public function saveContent($filenameResourceOrStream)
    {
        $resourceOrStream = $filenameResourceOrStream;
        if (is_string($filenameResourceOrStream)) {
            $resourceOrStream = fopen($filenameResourceOrStream, 'w+');
        }

        $stream = Psr7\stream_for($resourceOrStream);
        Psr7\copy_to_stream($this->getBinaryContentStream(), $stream);

        if (!is_string($filenameResourceOrStream)
            && !($filenameResourceOrStream instanceof StreamInterface)) {
            // only detach if it wasn't a string or StreamInterface, so the
            // fopen call can be properly closed if it was
            $stream->detach();
        }
    }

    public function getContent($charset = MailMimeParser::DEFAULT_CHARSET)
    {
        $stream = $this->getContentStream($charset);
        if ($stream !== null) {
            return $stream->getContents();
        }
        return null;
    }

    public function getParent()
    {
        return $this->parent;
    }

    public function attachContentStream(StreamInterface $stream, $streamCharset = MailMimeParser::DEFAULT_CHARSET)
    {
        $ch = ($this->charsetOverride !== null) ? $this->charsetOverride : $this->getCharset();
        if ($ch !== null && $streamCharset !== $ch) {
            $this->charsetOverride = $streamCharset;
        }
        $this->ignoreTransferEncoding = true;
        $this->partStreamContainer->setContentStream($stream);
        $this->notify();
    }

    public function detachContentStream()
    {
        $this->partStreamContainer->setContentStream(null);
        $this->notify();
    }

    public function setContent($resource, $charset = MailMimeParser::DEFAULT_CHARSET)
    {
        $stream = Psr7\stream_for($resource);
        $this->attachContentStream($stream, $charset);
        // this->notify() called in attachContentStream
    }

    public function save($filenameResourceOrStream)
    {
        $resourceOrStream = $filenameResourceOrStream;
        if (is_string($filenameResourceOrStream)) {
            $resourceOrStream = fopen($filenameResourceOrStream, 'w+');
        }

        $partStream = $this->getStream();
        $partStream->rewind();
        $stream = Psr7\stream_for($resourceOrStream);
        Psr7\copy_to_stream($partStream, $stream);

        if (!is_string($filenameResourceOrStream)
            && !($filenameResourceOrStream instanceof StreamInterface)) {
            // only detach if it wasn't a string or StreamInterface, so the
            // fopen call can be properly closed if it was
            $stream->detach();
        }
    }

    public function __toString()
    {
        return $this->getStream()->getContents();
    }
}
