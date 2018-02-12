<?php

namespace Tests;

use Mockery;
use Laravel\Scout\Builder;
use Illuminate\Database\Eloquent\Collection;
use Tests\Fixtures\ElasticsearchEngineTestModel;
use Gtk\LaravelScoutElasticsearch\ElasticsearchEngine;

class ElasticsearchEngineTest extends AbstractTestCase
{
    public function test_update_adds_objects_to_index()
    {
        $client = Mockery::mock('Elasticsearch\Client');

        $client->shouldReceive('bulk')->with([
            'body' => [
                [
                    'index' => [
                        '_index' => 'table',
                        '_type' => 'table',
                        '_id' => 1,
                    ],
                ],
                [
                    'id' => 1,
                ],
            ],
        ]);

        $engine = new ElasticsearchEngine($client);

        $engine->update(Collection::make([new ElasticsearchEngineTestModel]));
    }

    public function test_delete_removes_objects_to_index()
    {
        $client = Mockery::mock('Elasticsearch\Client');

        $client->shouldReceive('bulk')->with([
            'body' => [
                [
                    'delete' => [
                        '_index' => 'table',
                        '_type' => 'table',
                        '_id' => 1,
                    ],
                ],
            ],
        ]);

        $engine = new ElasticsearchEngine($client);

        $engine->delete(Collection::make([new ElasticsearchEngineTestModel]));
    }

    public function test_search_sends_correct_parameters_to_elasticsearch()
    {
        $client = Mockery::mock('Elasticsearch\Client');

        $client->shouldReceive('search');

        $engine = new ElasticsearchEngine($client);

        $builder = new Builder(new ElasticsearchEngineTestModel, 'zonda');

        $builder->where('foo', 1);

        $engine->search($builder);
    }

    public function test_map_correctly_maps_results_to_models()
    {
        $client = Mockery::mock('Elasticsearch\Client');

        $engine = new ElasticsearchEngine($client);

        $model = Mockery::mock('StdClass');

        $model->shouldReceive('newQuery')->andReturn($model);

        $model->shouldReceive('getKeyName')->andReturn('id');

        $model->shouldReceive('getQualifiedKeyName')->andReturn('id');

        $model->shouldReceive('whereIn')->once()->with('id', [1])->andReturn($model);

        $model->shouldReceive('get')->once()->andReturn(Collection::make([new ElasticsearchEngineTestModel]));

        $results = $engine->map([
            'hits' => [
                'total' => 1,
                'hits' => [
                    [
                        '_id' => 1,
                        '_source' => ['id' => 1],
                    ],
                ],
            ],
        ], $model);

        $this->assertEquals(1, count($results));
    }

    // public function test_real_elasticsearch_update_and_search()
    // {
    //     $engine = $this->getRealElasticsearchEngine();

    //     $engine->update(Collection::make([new ElasticsearchEngineTestModel]));

    //     $builder = new Builder(new ElasticsearchEngineTestModel, '1');

    //     $builder->where('id', 1);

    //     $results = $engine->search($builder);

    //     $this->assertEquals(1, $results['hits']['total']);

    //     $this->assertEquals('1', $results['hits']['hits'][0]['_id']);

    //     $this->assertEquals(['id' => 1], $results['hits']['hits'][0]['_source']);

    //     $builder->where('title', 'zonda');

    //     $results = $engine->search($builder);

    //     $this->assertEquals(0, $results['hits']['total']);
    // }

    // public function test_real_elasticsearch_delete()
    // {
    //     $engine = $this->getRealElasticsearchEngine();

    //     $collection = Collection::make([new ElasticsearchEngineTestModel]);

    //     $engine->update($collection);

    //     $builder = new Builder(new ElasticsearchEngineTestModel, '1');

    //     $engine->delete($collection);

    //     $builder = new Builder(new ElasticsearchEngineTestModel, '1');

    //     $results = $engine->search($builder);

    //     $this->assertEquals($results['hits']['total'], 0);
    // }

    protected function getRealElasticsearchEngine()
    {
        $client = $this->getRealElasticsearchClient();

        $this->markSkippedIfMissingElasticsearch($client);

        $this->resetIndex($client);

        return new ElasticsearchEngine($client);
    }
}
