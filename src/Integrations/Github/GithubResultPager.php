<?php

declare(strict_types=1);

namespace Packeton\Integrations\Github;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[Exclude]
class GithubResultPager
{
    public static $perPage = 100;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $method,
        private readonly string $url,
        private readonly array $params
    ) {
    }

    public function all(): array
    {
        $params = $this->params;
        $params['query']['per_page'] = self::$perPage;

        $processed = [];
        $url = $this->url;
        $result = [];

        while (true) {
            $response = $this->httpClient->request($this->method, $url, $params);
            $processed[$url] = 1;
            $result = array_merge($result, $response->toArray());

            $pagination = $this->getPagination($response);
            $url = $pagination['next'] ?? null;
            if (null === $url || isset($processed[$url])) {
                break;
            }
        }

        return $result;
    }

    /**
     * @return array<string,string>
     */
    private function getPagination(ResponseInterface $response): array
    {
        if (null === ($header = $response->getHeaders()['link'][0] ?? null)) {
            return [];
        }

        $pagination = [];
        foreach (explode(',', $header) as $link) {
            preg_match('/<(.*)>; rel="(.*)"/i', trim($link, ','), $match);
            if (3 === count($match)) {
                $pagination[$match[2]] = $match[1];
            }
        }

        return $pagination;
    }
}
