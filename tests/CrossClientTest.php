<?php declare(strict_types=1);

use AndrewBreksa\RSMQ\Message;
use AndrewBreksa\RSMQ\RSMQClient;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

/**
 * Class CrossClientTest
 *
 * @author Andrew Breksa <andrew@andrewbreksa.com>
 * @group  cross-client
 */
class CrossClientTest extends TestCase
{

    /**
     * @var Client
     */
    protected $guzzle;

    /**
     * @var RSMQClient
     */
    protected $rsmq;

    /**
     * @var \Predis\Client
     */
    protected $predis;

    public function setUp(): void
    {
        $this->guzzle = new Client(
            [
                'http_errors' => false,
                'base_uri'    => '127.0.0.1:8101',
                'timeout'     => 10
            ]
        );

        $this->predis = new \Predis\Client(
            [
                'host' => '127.0.0.1',
                'port' => 6379
            ]
        );
        $this->rsmq = new RSMQClient($this->predis);
    }

    public function tearDown(): void
    {
        $this->predis->flushall();
    }

    public function testCreateQueue()
    {
        $queue = 'test-create-queue';
        $this->rsmq->createQueue($queue);
        $response = $this->guzzle->get('/queues/' . $queue);
        self::assertEquals(200, $response->getStatusCode());
        $response = $this->guzzle->get('/queues');
        self::assertEquals(200, $response->getStatusCode());
        $json = json_decode($response->getBody()->getContents(), true);
        self::assertTrue(in_array($queue, $json['queues']));
    }

    public function testCreateQueueFromJS()
    {
        $queue = 'test-create-queue-js';
        $response = $this->guzzle->post(
            '/queues/' . $queue,
            [
                'json' => [
                    "vt"      => 60,
                    "delay"   => 0,
                    "maxsize" => 2048
                ]
            ]
        );
        self::assertEquals(200, $response->getStatusCode());
        $queueInfo = $this->rsmq->getQueueAttributes($queue);
        self::assertEquals(60, $queueInfo->getVt());
    }

    public function testSendMessage()
    {
        $queue = 'test-create-queue';
        $this->rsmq->createQueue($queue);
        $id = $this->rsmq->sendMessage($queue, 'Test 1');
        $response = $this->guzzle->get('/messages/' . $queue);
        self::assertEquals(200, $response->getStatusCode());
        $json = json_decode($response->getBody()->getContents(), true);
        self::assertEquals($id, $json['id']);
        self::assertEquals('Test 1', $json['message']);
    }

    public function testSendMessageJs()
    {
        $queue = 'test-create-queue';
        $this->rsmq->createQueue($queue);
        $response = $this->guzzle->post(
            '/messages/' . $queue,
            [
                'json' => [
                    'message' => 'Test 2'
                ]
            ]
        );
        $id = json_decode($response->getBody()->getContents(), true)['id'];
        $message = $this->rsmq->receiveMessage($queue);
        self::assertInstanceOf(Message::class, $message);
        self::assertEquals($id, $message->getId());
        self::assertEquals('Test 2', $message->getMessage());
    }
}
