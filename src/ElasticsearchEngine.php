<?php

namespace Gtk\LaravelScoutElasticsearch;

use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Elasticsearch\Client as Elastic;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletes;

class ElasticsearchEngine extends Engine
{
    /**
     * The Elasticsearch client.
     *
     * @var \Elasticsearch\Client
     */
    protected $elastic;

    /**
     * Create a new engine instance.
     *
     * @param  \Elasticsearch\Client  $elastic
     * @return void
     */
    public function __construct(Elastic $elastic)
    {
        $this->elastic = $elastic;
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

        $index = $models->first()->searchableAs();

        if ($this->usesSoftDelete($models->first()) && config('scout.soft_delete', false)) {
            $models->each->pushSoftDeleteMetadata();
        }

        $params = ['body' => []];

        $i = 0;

        $models->each(function ($model) use ($index, &$params, &$i) {
            $array = array_merge(
                $model->toSearchableArray(), $model->scoutMetadata()
            );

            if (empty($array)) {
                return;
            }

            $params['body'][] = [
                'index' => [
                    '_index' => $index,
                    '_type' => $index,
                    '_id' => $model->getKey(),
                ]
            ];

            $params['body'][] = $array;

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
        $index = $models->first()->searchableAs();

        $params = ['body' => []];

        $i = 0;

        $models->each(function ($model) use ($index, &$params, &$i) {
            $params['body'][] = [
                'delete' => [
                    '_index' => $index,
                    '_type' => $index,
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
            'index' => $builder->model->searchableAs(),
            'type' => $builder->index ?: $builder->model->searchableAs(),
            'body' => [
                'query' => $this->buildRawQuery($builder, $options),
            ],
        ];

        if (isset($options['from'])) {
            $params['body']['from'] = $options['from'];
        }

        if (isset($options['size'])) {
            $params['body']['size'] = $options['size'];
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
     * Buidl Elasticsearch query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $options
     * @return array
     */
    protected function buildRawQuery(Builder $builder, array $options = [])
    {
        if (is_array($builder->query)) {
            return $builder->query;
        }

        $query = [
            'bool' => [
                'must' => [
                    [
                        'query_string' => [
                            'query' => "{$builder->query}",
                        ],
                    ],
                ]
            ]
        ];

        if (isset($options['numericFilters']) && count($options['numericFilters'])) {
            $query['bool']['must'] = array_merge(
                $query['bool']['must'],
                $options['numericFilters']
            );
        }

        return $query;
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
            return is_array($value) ?
                    ['terms' => [$key => $value]] :
                    ['match' => [$key => $value]];
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
        return collect($results['hits']['hits'])->pluck('_id')->values();
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

        $builder = in_array(SoftDeletes::class, class_uses_recursive($model))
                    ? $model->withTrashed() : $model->newQuery();

        $models = $builder->whereIn(
            $model->getQualifiedKeyName(),
            collect($results['hits']['hits'])->pluck('_id')->values()->all()
        )->get()->keyBy($model->getKeyName());

        return Collection::make($results['hits']['hits'])->map(function ($hit) use ($models) {
            $key = $hit['_id'];

            if (isset($models[$key])) {
                return $models[$key];
            }
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

    /**
     * Determine if the given model uses soft deletes.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    protected function usesSoftDelete($model)
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model));
    }
}
