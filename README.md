# Laravel Scout Elasticsearch

This package provides a Elasticsearch driver for [Laravel Scout](https://laravel.com/docs/5.6/scout).

## Installation

First, install Laravel Scout Elasticsearch via the Composer package manager:

    composer require gtk/laravel-scout-elasticsearch

When using the Elasticsearch driver, you should configure your Elasticsearch `hosts` in your `config/scout.php` configuration file.

    'elasticsearch' => [
        'hosts' => [
            env('ELASTICSEARCH_HOST', 'http://localhost:9200'),
        ],
    ],

## Usage

Default usage can be found on the [Laravel Scout documentation](https://laravel.com/docs/scout).

You may begin searching a model using the `search` method. The search method accepts a single string that will be used to search your models. You should then chain the `get` method onto the search query to retrieve the Eloquent models that match the given search query:

    $orders = App\Order::search('Star Trek')->get();
    
In addition, the `search` method accepts an array that will be used as an Elasticsearch raw query to perform an advanced search:

    $orders = App\Order::search([
        'query' => [
            'query_string' => [
                'query' => 'Star Trek',
            ],
        ],
    ])->get();

You can check [Elastic document](https://www.elastic.co/guide/en/elasticsearch/client/php-api/current/_search_operations.html) for more information.

## License

Laravel Scout Elasticsearch is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
