<?php

namespace Drupal\world_api\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class EmbedController implements ContainerInjectionInterface {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly Settings $settings,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('http_client'),
      $container->get('settings'),
    );
  }

  /**
   * GET /api/embed?url=
   *
   * Calls Iframely server-side and returns a SAFE iframe payload.
   * We intentionally do NOT return raw html/script.
   */
  public function embed(Request $request): JsonResponse {
    $url = trim((string) $request->query->get('url', ''));

    if ($url === '') throw new BadRequestHttpException('Missing param: url');
    if (strlen($url) > 2048) throw new BadRequestHttpException('URL too long');
    if (!preg_match('#^https?://#i', $url)) {
      throw new BadRequestHttpException('URL must start with http:// or https://');
    }

    // Very light parsing guard.
    $parts = @parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
      throw new BadRequestHttpException('Invalid URL');
    }

    $cfg = $this->settings->get('iframely', []);
    $key = is_array($cfg) ? (string) ($cfg['api_key'] ?? '') : '';
    if ($key === '') {
      return new JsonResponse([
        'ok' => false,
        'error' => 'Iframely API key missing in settings.php ($settings["iframely"]["api_key"]).',
      ], 500);
    }

    $endpoint = 'https://iframe.ly/api/iframely';

    try {
      $res = $this->httpClient->request('GET', $endpoint, [
        'query' => [
          'url' => $url,
          'key' => $key,
        ],
        'timeout' => 8,
        'headers' => [
          'Accept' => 'application/json',
        ],
      ]);
    }
    catch (\Throwable $e) {
      return new JsonResponse([
        'ok' => false,
        'error' => 'Iframely request failed',
        'detail' => $e->getMessage(),
      ], 502);
    }

    $status = $res->getStatusCode();
    $body = (string) $res->getBody();
    $data = json_decode($body, true);

    if ($status < 200 || $status >= 300 || !is_array($data)) {
      return new JsonResponse([
        'ok' => false,
        'error' => 'Iframely returned an error',
        'status' => $status,
      ], 502);
    }

    $html = isset($data['html']) && is_string($data['html']) ? $data['html'] : '';
    $meta = $data['meta'] ?? null;
    $links = $data['links'] ?? null;

    $iframeSrc = $this->extractIframeSrc($html);
    $height = $this->extractHeightPx($html);

    // Bandcamp reliability: force canonical iframe endpoint + key.
    $iframeSrc = $this->normalizeToCanonicalIframe($iframeSrc, $url, $key);

    $payload = [
      'ok' => (bool) $iframeSrc,
      'url' => $url,
      'iframe_src' => $iframeSrc,
      'height' => $height,
      'meta' => $meta,
      'links' => $links,
    ];

    $cache = new CacheableMetadata();
    $cache->setCacheMaxAge(300);
    $cache->setCacheContexts(['url.query_args']);

    $response = new CacheableJsonResponse($payload);
    $response->addCacheableDependency($cache);
    return $response;
  }

  private function extractIframeSrc(string $html): ?string {
    if ($html === '') return null;

    if (preg_match('/<iframe[^>]+src="([^"]+)"/i', $html, $m)) {
      return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }
    if (preg_match("/<iframe[^>]+src='([^']+)'/i", $html, $m)) {
      return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }
    return null;
  }

  private function extractHeightPx(string $html): ?int {
    if (preg_match('/height:\s*([0-9]{2,4})px/i', $html, $m)) {
      return (int) $m[1];
    }
    return null;
  }

  /**
   * Always return:
   *   https://cdn.iframe.ly/api/iframe?url=<encoded_url>&key=<key>
   */
  private function normalizeToCanonicalIframe(?string $src, string $originalUrl, string $key): string {
    $effectiveUrl = $originalUrl;

    if (is_string($src) && $src !== '') {
      $srcTrim = trim($src);

      // protocol-relative -> https
      if (str_starts_with($srcTrim, '//')) {
        $srcTrim = 'https:' . $srcTrim;
      }

      // If it's absolute and has ?url=..., use that.
      $parts = @parse_url($srcTrim);
      if (is_array($parts) && !empty($parts['query'])) {
        parse_str($parts['query'], $q);
        if (!empty($q['url']) && is_string($q['url'])) {
          $decoded = urldecode($q['url']);
          if (preg_match('#^https?://#i', $decoded)) {
            $effectiveUrl = $decoded;
          }
        }
      }
    }

    $encoded = rawurlencode($effectiveUrl);
    return "https://cdn.iframe.ly/api/iframe?url={$encoded}&key={$key}";
  }

}
