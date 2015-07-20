<?php

namespace ArDev\HALRestClient;

use Guzzle\Http\Exception\ClientErrorResponseException;
use Guzzle\Service\Client as Guzzle;

use Rize\UriTemplate;
use ArDev\HAL\Navigator;

class Client
{
    /**
     * Guzzle client
     *
     * @var Guzzle
     */
    protected $guzzleClient;

    /**
     * Index url
     *
     * @var string
     */
    protected $indexUrl;

    /**
     * @var UriTemplate
     */
    protected $uriTemplater;

    /**
     * @var array
     */
    protected $cachedRelations;

    /**
     * @param Guzzle  $guzzleClient
     * @param string  $baseUrl
     */
    public function __construct(
        Guzzle $guzzleClient,
        $indexUrl
    ) {
        $this->guzzleClient = $guzzleClient;
        $this->indexUrl = $indexUrl;
        $this->uriTemplater = new UriTemplate();
    }

    /**
     * Get the guzzle client
     *
     * @return Guzzle
     */
    public function getGuzzleClient()
    {
        return $this->guzzleClient;
    }

    /**
     * @param  string $relation
     * @throws ClientErrorResponseException | InvalidArgumentException
     * @return string
     */
    public function getRelation($relation)
    {
        if (null === ($relations = $this->cachedRelations)) {
            try {
                $request = $this->guzzleClient->get(
                    $this->indexUrl
                );
                $result = $this->guzzleClient->send($request)->json();
                $relations = $result['_links'];
                $this->cachedRelations = $relations;
            } catch (ClientErrorResponseException $e) {
                throw $e;
            }
        }

        if (!array_key_exists($relation, $relations)) {
            throw new \InvalidArgumentException(sprintf('Relation %s not found', $relation));
        }

        return $relations[$relation]['href'];
    }

    /**
     * Render a templated uri with the given parameters
     *
     * @param  string $templatedUri Templated URI that match RFC6570
     * @param  array  $parameters
     * @return string
     */
    protected function renderUri($templatedUri, $parameters = array())
    {
        return $this->uriTemplater->expand($templatedUri, $parameters);
    }

    /**
     * @param string $relation
     * @param array  $parameters
     * @throws ClientErrorResponseException
     * @return Navigator|array|string|int|bool|float
     */
    public function query($relation, array $parameters = [])
    {
        $templatedUrl = $this->getRelation($relation);
        $url = $this->renderUri($templatedUrl, $parameters);

        try {
            $request = $this->guzzleClient->get(
                $url
            );
            $query = $this->guzzleClient->send($request);
        } catch (ClientErrorResponseException $e) {
            throw $e;
        }

        if ('application/hal+json' === $query->getContentType()) {
            return new Navigator($query->json());
        }

        return $query->json();
    }

    /**
     * @param string $relation
     * @param array  $parameters
     * @throws ServiceUnavailableHttpException
     * @return Navigator|mixed
     */
    public function command($relation, array $parameters = [], $files = [])
    {
        $templatedUrl = $this->getRelation($relation);

        // expand template url + remove templated params from parameters
        $url = $this->renderUri($templatedUrl, $parameters);
        $templatedParameters = $this->uriTemplater->extract($templatedUrl, $url);
        $parameters = array_diff_key($parameters, $templatedParameters);

        try {
            $headers = [];

            if (!$files) {
                $headers = ['Content-Type' => 'application/json'];
                $parameters = json_encode($parameters);
            }

            $request = $this->guzzleClient->post(
                $url,
                $headers,
                $parameters
            );

            $request->addPostFiles($files);

            $command = $this->guzzleClient->send($request);
        } catch (ClientErrorResponseException $e) {
            throw $e;
        }

        switch ($command->getContentType()) {
            case 'application/hal+json':
                $response = new Navigator($command->json());
                break;
            case 'application/json':
                $response = $command->json();
                break;
            default:
                $response = $command->getBody(true);
                break;
        }

        return $response;
    }
}
