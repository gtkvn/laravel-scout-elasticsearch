<?php

namespace Tests;

use Mockery;
use PHPUnit_Framework_TestCase;
use Elasticsearch\ClientBuilder;
use Elasticsearch\Client as Elastic;
use Elasticsearch\Common\Exceptions\Curl\CouldNotConnectToHost;

abstract class AbstractTestCase extends PHPUnit_Framework_TestCase
{
    public function tearDown()
    {
        Mockery::close();

        parent::tearDown();
    }

    protected function getRealElasticsearchClient()
    {
        return ClientBuilder::create()
            ->setHosts(['127.0.0.1:9200'])
            ->setRetries(0)
            ->build();
    }

    protected function resetIndex(Elastic $client)
    {
        $data = ['index' => 'table'];

        if ($client->indices()->exists($data)) {
            $client->indices()->delete($data);
        }

        $client->indices()->create($data);
    }

    protected function markSkippedIfMissingElasticsearch(Elastic $client)
    {
        try {
            $client->cluster()->health();
        } catch (CouldNotConnectToHost $e) {
            $this->markTestSkipped('Could not connect to Elasticsearch');
        }
    }
}
