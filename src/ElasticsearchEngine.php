<?php

namespace Gtk\LaravelScoutElasticsearch;

use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Elasticsearch\Client as Elastic;
use Illuminate\Database\Eloquent\Collection;

class ElasticsearchEngine extends Engine
{
    /**
     * The Elasticsearch client.
     *
     * @var \Elasticsearch\Client
     */
    protected $elastic;
    /**
     * The Elasticsearch index.
     *
     * @var string
     */
    protected $index;

    /**
     * Create a new engine instance.
     *
     * @param  \Elasticsearch\Client  $elastic
     * @return void
     */
    public function __construct(Elastic $elastic, $index)
    {
        $this->elastic = $elastic;

        $this->index = $index;
    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $params = ['body' => []];

        $i = 0;

        $models->each(function ($model) use (&$params, &$i) {
            $params['body'][] = [
                'index' => [
                    '_index' => $this->index,
                    '_type' => $model->searchableAs(),
                    '_id' => $model->getKey(),
                ]
            ];

            $params['body'][] = $model->toSearchableArray();

            ++$i;

            // Every 1000 documents stop and send the bulk request
            if ($i % 1000 == 0) {
                $this->elastic->bulk($params);

                // erase the old bulk request
                $params = ['body' => []];
            }
        });

        // Send the last batch if it exists
        if (! empty($params['body'])) {
            $this->elastic->bulk($params);
        }
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
    {
        $params = ['body' => []];

        $i = 0;

        $models->each(function ($model) use (&$params, &$i) {
            $params['body'][] = [
                'delete' => [
                    '_index' => $this->index,
                    '_type' => $model->searchableAs(),
                    '_id' => $model->getKey(),
                ]
            ];

            ++$i;

            // Every 1000 documents stop and send the bulk request
            if ($i % 1000 == 0) {
                $this->elastic->bulk($params);

                // erase the old bulk request
                $params = ['body' => []];
            }
        });

        // Send the last batch if it exists
        if (! empty($params['body'])) {
            $this->elastic->bulk($params);
        }
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, array_filter([
            'numericFilters' => $this->filters($builder),
            'size' => $builder->limit ?: 10000,
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, [
            'numericFilters' => $this->filters($builder),
            'from' => (($page * $perPage) - $perPage),
            'size' => $perPage,
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $params = [
            'index' => $this->index,
            'type' => $builder->index ?: $builder->model->searchableAs(),
            'body' => [
                'query' => [
                    'query_string' => [
                        'query' => "{$builder->query}",
                    ],
                ],
            ],
        ];

        if (isset($options['from'])) {
            $params['body']['from'] = $options['from'];
        }

        if (isset($options['size'])) {
            $params['body']['size'] = $options['size'];
        }

        if (isset($options['numericFilters']) && count($options['numericFilters'])) {
            $params['body']['query']['bool']['must'] = array_merge(
                $params['body']['query']['bool']['must'],
                $options['numericFilters']
            );
        }

        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                $this->elastic,
                $builder->query,
                $params
            );
        }

        return $this->elastic->search($params);
    }

    /**
     * Get the filter array for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function filters(Builder $builder)
    {
        return collect($builder->wheres)->map(function ($value, $key) {
            if (is_array($value)) {
                return ['terms' => [$key => $value]];
            }

            return ['match' => [$key => $value]];
        })->values()->all();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results['hits']['hits'])
            ->pluck('_id')
            ->values()
            ->all();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map($results, $model)
    {
        if ($results['hits']['total'] === 0) {
            return Collection::make();
        }

        $keys = $this->mapIds($results);

        $models = $model->whereIn(
            $model->getKeyName(),
            $keys
        )->get()->keyBy($model->getKeyName());

        return collect($results['hits']['hits'])->map(function ($hit) use ($model, $models) {
            return isset($models[$hit['_id']]) ? $models[$hit['_id']] : null;
        })->filter()->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['hits']['total'];
    }
}