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

You may begin searching a model using the `search` method. The search method accepts a single string that will be used to search your models. You should then chain the `get` method onto the search query to retrieve the Eloquent models that match the given search query:

    $orders = App\Order::search('Star Trek')->get();
    
The `search` method also accepts an Elasticsearch raw query that will be used to perform an advanced search. Check Elastic document for more information.

    $orders = App\Order::search([
        'query' => [
            'query_string' => [
                'query' => 'Star Trek',
            ],
        ],
    ])->get();
    
## License

Laravel Scout Elasticsearch is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
