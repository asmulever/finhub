<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\Analytics;

use FinHub\Application\Analytics\PredictionMarketFetcherInterface;
use FinHub\Infrastructure\Logging\LoggerInterface;

/**
 * Fetcher de Prediction Markets desde Yahoo Finance (trending).
 */
final class HttpYahooPredictionFetcher implements PredictionMarketFetcherInterface
{
    private const URL = 'https://finance.yahoo.com/markets/prediction/trending/';

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /** @inheritDoc */
    public function fetchTrending(): array
    {
        $html = $this->downloadHtml();
        $parsed = $this->parseEmbeddedJson($html);
        if ($parsed === null) {
            $parsed = $this->parseHtml($html);
        }
        if ($parsed === null) {
            throw new \RuntimeException('No se pudo parsear trending prediction markets');
        }
        $asOf = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        return [
            'as_of' => $asOf,
            'items' => $parsed,
        ];
    }

    private function downloadHtml(): string
    {
        $ch = curl_init(self::URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Accept-Encoding: gzip'],
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($errno !== 0 || $body === false) {
            throw new \RuntimeException('Error HTTP Yahoo Finance: ' . $err);
        }
        $decoded = @gzdecode($body);
        return $decoded !== false ? $decoded : $body;
    }

    /**
     * Intenta encontrar JSON embebido utilizable.
     * @return array<int,array<string,mixed>>|null
     */
    private function parseEmbeddedJson(string $html): ?array
    {
        if (trim($html) === '') {
            return null;
        }
        $matches = [];
        if (!preg_match_all('/<script[^>]+type="application\/json"[^>]*>(.*?)<\/script>/si', $html, $matches)) {
            return null;
        }
        foreach ($matches[1] as $rawJson) {
            $decoded = json_decode($rawJson, true);
            if (!is_array($decoded)) {
                continue;
            }
            // Algunos scripts tienen un campo body con JSON serializado.
            if (isset($decoded['body']) && is_string($decoded['body'])) {
                $inner = json_decode($decoded['body'], true);
                if (is_array($inner)) {
                    $decoded = $inner;
                }
            }
            $items = $this->extractFromArray($decoded);
            if (!empty($items)) {
                return $items;
            }
        }
        return null;
    }

    /**
     * Busca estructuras con claves de prediction en un array anidado.
     * @param mixed $data
     * @return array<int,array<string,mixed>>
     */
    private function extractFromArray($data): array
    {
        if (!is_array($data)) {
            return [];
        }
        // Heurística: buscar nodos con outcomes y title.
        $items = [];
        $iterator = function ($node) use (&$iterator, &$items) {
            if (!is_array($node)) {
                return;
            }
            $hasOutcomes = isset($node['outcomes']) && is_array($node['outcomes']);
            $hasTitle = isset($node['title']) || isset($node['name']);
            if ($hasOutcomes && $hasTitle) {
                $items[] = $this->normalizeItem($node);
                return;
            }
            foreach ($node as $value) {
                $iterator($value);
            }
        };
        $iterator($data);
        return $items;
    }

    /**
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private function normalizeItem(array $node): array
    {
        $id = (string) ($node['id'] ?? $node['slug'] ?? $node['name'] ?? md5(json_encode($node)));
        $title = (string) ($node['title'] ?? $node['name'] ?? $id);
        $category = isset($node['category']) ? (string) $node['category'] : '';
        $url = isset($node['url']) ? (string) $node['url'] : '';
        $outcomes = [];
        foreach ($node['outcomes'] ?? [] as $outcome) {
            if (!is_array($outcome)) {
                continue;
            }
            $name = (string) ($outcome['name'] ?? $outcome['title'] ?? '');
            if ($name === '') {
                continue;
            }
            $prob = isset($outcome['probability']) ? (float) $outcome['probability'] : null;
            if ($prob !== null && $prob > 1) {
                $prob = $prob / 100;
            }
            $price = isset($outcome['price']) ? (float) $outcome['price'] : null;
            $outcomes[] = [
                'name' => $name,
                'probability' => $prob,
                'price' => $price,
            ];
        }
        return [
            'id' => $id,
            'title' => $title,
            'category' => $category,
            'url' => $url,
            'source_timestamp' => $node['source_timestamp'] ?? null,
            'outcomes' => $outcomes,
        ];
    }

    /**
     * Fallback robusto por scraping HTML con selectores tolerantes.
     * @return array<int,array<string,mixed>>|null
     */
    public function parseHtml(string $html): ?array
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        if (!@$doc->loadHTML($html)) {
            return null;
        }
        $xpath = new \DOMXPath($doc);
        $items = [];
        $seen = [];

        // Featured card (sec:predict-featured-card)
        $links = $xpath->query('//a[contains(@data-ylk,"predict-featured-card") and contains(@data-ylk,"aid:")]');
        foreach ($links as $link) {
            $this->collectFromLink($link, $xpath, $items, $seen);
        }
        // List cards
        $links = $xpath->query('//a[contains(@data-ylk,"predict-event-card-list") and contains(@data-ylk,"aid:")]');
        foreach ($links as $link) {
            $this->collectFromLink($link, $xpath, $items, $seen);
        }

        return empty($items) ? null : array_values($items);
    }

    /**
     * @param \DOMElement $link
     * @param \DOMXPath $xpath
     * @param array<string,array<string,mixed>> $items
     * @param array<string,bool> $seen
     */
    private function collectFromLink(\DOMElement $link, \DOMXPath $xpath, array &$items, array &$seen): void
    {
        $ylk = (string) $link->getAttribute('data-ylk');
        if (!preg_match('/aid:([^;]+)/', $ylk, $m)) {
            return;
        }
        $stableId = $m[1];
        if (isset($seen[$stableId])) {
            return;
        }
        $seen[$stableId] = true;

        $title = trim($link->textContent);
        $href = (string) $link->getAttribute('href');
        if ($href !== '' && str_starts_with($href, '/')) {
            $href = 'https://finance.yahoo.com' . $href;
        }

        // Ubicar contenedor de card
        $card = $this->findCardContainer($link);

        // Categoria: intentar primer <a> en nav dentro del contenedor o antes.
        $category = '';
        if ($card !== null) {
            $catNode = $xpath->query('.//nav//a', $card);
            if ($catNode->length > 0) {
                $category = trim($catNode->item(0)->textContent);
            } else {
                $catPrev = $xpath->query('(preceding::nav)[1]//a', $card);
                if ($catPrev->length > 0) {
                    $category = trim($catPrev->item(0)->textContent);
                }
            }
        }

        $outcomes = $card ? $this->extractOutcomes($xpath, $card) : [];

        $items[$stableId] = [
            'id' => $stableId,
            'title' => $title,
            'category' => $category,
            'url' => $href,
            'source_timestamp' => null,
            'outcomes' => $outcomes,
        ];
    }

    private function findCardContainer(\DOMElement $node): ?\DOMElement
    {
        for ($n = $node; $n !== null; $n = $n->parentNode) {
            if ($n instanceof \DOMElement) {
                $dt = $n->getAttribute('data-testid');
                if ($dt === 'card-container') {
                    return $n;
                }
            }
        }
        return null;
    }

    /**
     * @param \DOMXPath $xpath
     * @param \DOMElement $card
     * @return array<int,array<string,mixed>>
     */
    private function extractOutcomes(\DOMXPath $xpath, \DOMElement $card): array
    {
        $rows = $xpath->query('.//div[contains(@class,"multi-market-item")]', $card);
        $outcomes = [];
        foreach ($rows as $row) {
            $nameNode = $xpath->query('.//div[contains(@class,"title-wrapper")]//span', $row)->item(0);
            $probNode = $xpath->query('.//div[contains(@class,"chance-display")]//span', $row)->item(0);
            $name = $nameNode ? trim($nameNode->textContent) : '';
            $prob = null;
            if ($probNode && preg_match('/([0-9.,]+)/', $probNode->textContent, $m)) {
                $prob = (float) str_replace(',', '', $m[1]);
                if ($prob > 1) {
                    $prob = $prob / 100; // convertir de % a fracción
                }
            }
            if ($name === '' || $prob === null) {
                continue;
            }
            // Intentar precio dentro de los botones de outcome (Yes/No)
            $price = null;
            $btn = $xpath->query('.//div[contains(@class,"outcome-buttons")]//button', $row)->item(0);
            if ($btn) {
                if (preg_match('/([0-9]+\.?[0-9]*)/', $btn->textContent, $pm)) {
                    $price = (float) $pm[1];
                }
            }
            $outcomes[] = [
                'name' => $name,
                'probability' => $prob,
                'price' => $price,
            ];
        }
        return $outcomes;
    }
}
