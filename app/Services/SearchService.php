<?php

namespace App\Services;

use App\Product;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Yish\Generators\Foundation\Service\Service;

use function Functional\map;

class SearchService extends Service
{
    public function getSearchResults($query)
    {
        $client = new Client();

        $response = $client->request(
            'GET',
            env('UQ_API_SEARCH'),
            [
                'query' => [
                    'limit' => '10',
                    'order' => 'asc',
                    'page' => '1',
                    'q' => $query,
                    'sort' => 'flagSortWeightage'
                ]
            ]
        );

        return json_decode($response->getBody());
    }

    public function getProducts($searchResults)
    {
        $productIDs = map($searchResults->records, function ($record) {
            return $record->id;
        });

        return Product::find($productIDs);
    }
}
