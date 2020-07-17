<?php

use AndrewBreksa\RSMQ\Exceptions\QueueParametersValidationException;
use AndrewBreksa\RSMQ\RSMQClient;
use PHPUnit\Framework\TestCase;
use Predis\Client;

class RSMQTest extends TestCase
{
    /**
     * @var RSMQClient
     */
    private $rsmq;

    public function setUp(): void
    {
        $redis = new Client(
            [
                'host' => '127.0.0.1',
                'port' => 6379
            ]
        );
        $this->rsmq = new RSMQClient($redis);
    }

    public function testScriptsShouldInitialized(): void
    {
        $reflection = new ReflectionClass($this->rsmq);

        $recvMsgRef = $reflection->getProperty('receiveMessageSha1');
        $recvMsgRef->setAccessible(true);

        self::assertSame(40, strlen($recvMsgRef->getValue($this->rsmq)));

        $popMsgRef = $reflection->getProperty('popMessageSha1');
        $popMsgRef->setAccessible(true);

        self::assertSame(40, strlen($popMsgRef->getValue($this->rsmq)));
    }

    public function testCreateQueue(): void
    {
        self::assertTrue($this->rsmq->createQueue('foo'));
    }

    public function testCreateQueueWithInvalidName(): void
    {
        $this->expectException(QueueParametersValidationException::class);
        $this->expectExceptionMessage('Invalid queue name');
        $this->rsmq->createQueue(' sad');
    }

    public function testCreateQueueWithBigVt(): void
    {
        $this->expectException(QueueParametersValidationException::class);
        $this->expectExceptionMessage('Visibility time must be between');
        $this->rsmq->createQueue('foo', PHP_INT_MAX);
    }

    public function testCreateQueueWithNegativeVt(): void
    {
        $this->expectException(QueueParametersValidationException::class);
        $this->expectExceptionMessage('Visibility time must be between');
        $this->rsmq->createQueue('foo', -1);
    }

    public function testCreateQueueWithBigDelay(): void
    {
        $this->expectException(QueueParametersValidationException::class);
        $this->expectExceptionMessage('Delay must be between');
        $this->rsmq->createQueue('foo', 30, PHP_INT_MAX);
    }

    public function testCreateQueueWithNegativeDelay(): void
    {
        $this->expectException(QueueParametersValidationException::class);
        $this->expectExceptionMessage('Delay must be between');
        $this->rsmq->createQueue('foo', 30, -1);
    }

    public function testCreateQueueWithBigMaxSize(): void
    {
        $this->expectException(QueueParametersValidationException::class);
        $this->expectExceptionMessage('Maximum message size must be between');
        $this->rsmq->createQueue('foo', 30, 0, PHP_INT_MAX);
    }

    public function testCreateQueueWithSmallMaxSize(): void
    {
        $this->expectException(QueueParametersValidationException::class);
        $this->expectExceptionMessage('Maximum message size must be between');
        $this->rsmq->createQueue('foo', 30, 0, 1023);
    }

    public function testGetQueueAttributes(): void
    {
        $vt = 40;
        $delay = 60;
        $maxSize = 1024;
        $this->rsmq->createQueue('foo', $vt, $delay, $maxSize);

        $attributes = $this->rsmq->getQueueAttributes('foo');

        self::assertSame($vt, $attributes->getVt());
        self::assertSame($delay, $attributes->getDelay());
        self::assertSame($maxSize, $attributes->getMaxSize());
        self::assertSame(0, $attributes->getMessageCount());
        self::assertSame(0, $attributes->getHiddenMessageCount());
        self::assertSame(0, $attributes->getTotalReceived());
        self::assertSame(0, $attributes->getTotalSent());
        self::assertNotEmpty($attributes->getCreated());
        self::assertNotEmpty($attributes->getModified());
    }

    public function testGetQueueAttributesThatDoesNotExists(): void
    {
        $this->expectExceptionMessage('Queue not found.');
        $this->rsmq->getQueueAttributes('not_existent_queue');
    }

    public function testCreateQueueMustThrowExceptionWhenQueueExists(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Queue already exists.');

        $this->rsmq->createQueue('foo');
        $this->rsmq->createQueue('foo');
    }

    public function testListQueues(): void
    {
        self::assertEmpty($this->rsmq->listQueues());

        $this->rsmq->createQueue('foo');
        self::assertSame(['foo'], $this->rsmq->listQueues());
    }

    public function testValidateWithInvalidQueueName(): void
    {
        $this->expectExceptionMessage('Invalid queue name');
        $this->invokeMethod(
            $this->rsmq, 'validate', [
                           ['queue' => ' foo']
                       ]
        );

    }

    /**
     * @param  object            $object
     * @param  string            $methodName
     * @param  array<int, mixed> $parameters
     * @return mixed
     * @throws ReflectionException
     */
    public function invokeMethod(object &$object, string $methodName, array $parameters = array())
    {
        $reflection = new ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    public function testValidateWithInvalidVt(): void
    {
        $this->expectExceptionMessage('Visibility time must be');
        $this->invokeMethod(
            $this->rsmq, 'validate', [
                           ['vt' => '-1']
                       ]
        );
    }

    public function testValidateWithInvalidId(): void
    {
        $this->expectExceptionMessage('Invalid message id');
        $this->invokeMethod(
            $this->rsmq, 'validate', [
                           ['id' => '123456']
                       ]
        );
    }

    public function testValidateWithInvalidDelay(): void
    {
        $this->expectExceptionMessage('Delay must be');
        $this->invokeMethod(
            $this->rsmq, 'validate', [
                           ['delay' => 99999999]
                       ]
        );
    }

    public function testValidateWithInvalidMaxSize(): void
    {
        $this->expectExceptionMessage('Maximum message size must be');
        $this->invokeMethod(
            $this->rsmq,
            'validate',
            [
                ['maxsize' => 512]
            ]
        );
    }

    public function testSendMessage(): void
    {
        $this->rsmq->createQueue('foo');
        $id = $this->rsmq->sendMessage('foo', 'foobar');
        self::assertSame(32, strlen($id));
        $attributes = $this->rsmq->getQueueAttributes('foo');
        self::assertSame(1, $attributes->getMessageCount());
        self::assertSame(0, $attributes->getHiddenMessageCount());
        self::assertSame(0, $attributes->getTotalReceived());
        self::assertSame(1, $attributes->getTotalSent());
    }

    public function testSendMessageRealtime(): void
    {
        $rsmq = new RSMQClient(new Client(['host' => '127.0.0.1', 'port' => 6379]), 'rsmq', true);
        $rsmq->createQueue('foo');
        $id = $rsmq->sendMessage('foo', 'foobar');
        self::assertSame(32, strlen($id));
    }

    public function testSendMessageWithBigMessage(): void
    {
        $this->rsmq->createQueue('foo');
        $bigStr = str_repeat(bin2hex(random_bytes(512)), 100);

        $this->expectExceptionMessage('Message too long');
        $this->rsmq->sendMessage('foo', $bigStr);
    }

    public function testDeleteMessage(): void
    {
        $this->rsmq->createQueue('foo');
        $id = $this->rsmq->sendMessage('foo', 'bar');
        self::assertTrue($this->rsmq->deleteMessage('foo', $id));
    }

    public function testReceiveMessage(): void
    {
        $queue = 'foo';
        $message = 'Hello World';
        $this->rsmq->createQueue($queue);
        $id = $this->rsmq->sendMessage($queue, $message);
        $received = $this->rsmq->receiveMessage($queue);

        self::assertSame($message, $received->getMessage());
        self::assertSame($id, $received->getId());
        self::assertNotEmpty($received->getFirstReceived());
        self::assertNotEmpty($received->getSent());
        self::assertSame(1, $received->getReceiveCount());
    }

    public function testReceiveMessageWhenNoMessageExists(): void
    {
        $queue = 'foo';
        $this->rsmq->createQueue($queue);
        $received = $this->rsmq->receiveMessage($queue);

        self::assertEmpty($received);
    }

    public function testChangeMessageVisibility(): void
    {
        $queue = 'foo';
        $this->rsmq->createQueue($queue);
        $id = $this->rsmq->sendMessage($queue, 'bar');
        self::assertTrue($this->rsmq->changeMessageVisibility($queue, $id, 60));

    }

    public function testGetQueue(): void
    {
        $queueName = 'foo';
        $vt = 30;
        $delay = 0;
        $maxSize = 65536;
        $this->rsmq->createQueue($queueName, $vt, $delay, $maxSize);
        $queue = $this->invokeMethod($this->rsmq, 'getQueue', [$queueName, true]);

        self::assertSame($vt, $queue['vt']);
        self::assertSame($delay, $queue['delay']);
        self::assertSame($maxSize, $queue['maxsize']);
        self::assertArrayHasKey('uid', $queue);
        self::assertSame(32, strlen($queue['uid']));
    }

    public function testGetQueueNotFound(): void
    {
        $this->expectExceptionMessage('Queue not found');
        $this->invokeMethod($this->rsmq, 'getQueue', ['notfound']);
    }

    public function testPopMessage(): void
    {
        $queue = 'foo';
        $message = 'bar';
        $this->rsmq->createQueue($queue);

        $id = $this->rsmq->sendMessage($queue, $message);
        $received = $this->rsmq->popMessage($queue);

        self::assertSame($id, $received->getId());
        self::assertSame($message, $received->getMessage());
    }

    public function testPopMessageWhenNoMessageExists(): void
    {
        $queue = 'foo';
        $this->rsmq->createQueue($queue);

        $received = $this->rsmq->popMessage($queue);

        self::assertEmpty($received);

    }

    public function testSetQueueAttributes(): void
    {
        $queue = 'foo';
        $vt = 100;
        $delay = 10;
        $maxsize = 2048;
        $this->rsmq->createQueue($queue);
        $attrs = $this->rsmq->setQueueAttributes($queue, $vt, $delay, $maxsize);

        self::assertSame($vt, $attrs->getVt());
        self::assertSame($delay, $attrs->getDelay());
        self::assertSame($maxsize, $attrs->getMaxSize());
    }

    public function tearDown(): void
    {
        try {
            $this->rsmq->deleteQueue('foo');
        } catch (Exception $_) {

        }

    }
}