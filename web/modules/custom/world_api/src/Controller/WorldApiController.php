<?php

namespace Drupal\world_api\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class WorldApiController implements ContainerInjectionInterface {

  private const MAX_ANCHOR_SPAN_TILES = 64;
  private const TILE_PX = 256;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AliasManagerInterface $aliasManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('path_alias.manager'),
      $container->get('file_url_generator'),
    );
  }

  /**
   * GET /api/world/viewport?x=&y=&w=&h=
   * (also supports xmin/xmax/ymin/ymax for legacy callers)
   */
  public function viewport(Request $request): JsonResponse {
    if ($denied = $this->requireBetaAccess()) {
      return $denied;
    }

    $z = $this->intOptionalParam($request, 'z', 10);

    $x = $this->intOptionalParam($request, 'x');
    $y = $this->intOptionalParam($request, 'y');
    $w = $this->intOptionalParam($request, 'w');
    $h = $this->intOptionalParam($request, 'h');

    $xmin = $this->intOptionalParam($request, 'xmin');
    $xmax = $this->intOptionalParam($request, 'xmax');
    $ymin = $this->intOptionalParam($request, 'ymin');
    $ymax = $this->intOptionalParam($request, 'ymax');

    if ($x !== null && $y !== null && $w !== null && $h !== null) {
      $xmin = $x;
      $ymin = $y;
      $xmax = $x + $w - 1;
      $ymax = $y + $h - 1;
    }
    elseif ($xmin === null || $xmax === null || $ymin === null || $ymax === null) {
      throw new BadRequestHttpException('Missing viewport params: expected x,y,w,h.');
    }

    if ($xmin > $xmax || $ymin > $ymax) {
      throw new BadRequestHttpException('Invalid bounds.');
    }

    $storage = $this->entityTypeManager->getStorage('node');

    // Query slightly beyond viewport because anchors can span multiple tiles.
    $span = self::MAX_ANCHOR_SPAN_TILES;
    $qxmin = $xmin - $span;
    $qymin = $ymin - $span;
    $qxmax = $xmax;
    $qymax = $ymax;

    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'tile')
      ->condition('status', 1)
      ->condition('field_z', $z)
      ->condition('field_role', 'anchor')
      ->condition('field_x', $qxmin, '>=')
      ->condition('field_x', $qxmax, '<=')
      ->condition('field_y', $qymin, '>=')
      ->condition('field_y', $qymax, '<=')
      ->execute();

    $cache = new CacheableMetadata();
    $cache->setCacheContexts(['url.query_args']);

    // Tile placement changes frequently during world-building.
    // Keep viewport responses from serving stale anchor/empty results.
    $cache->addCacheTags(['node_list:tile']);
    $cache->setCacheMaxAge(0);

    $anchors = [];
    if ($nids) {
      $tiles = $storage->loadMultiple($nids);

      foreach ($tiles as $tile) {
        $cache->addCacheableDependency($tile);

        $ax = (int) ($tile->get('field_x')->value ?? 0);
        $ay = (int) ($tile->get('field_y')->value ?? 0);
        $aw = max(1, (int) ($tile->get('field_w')->value ?: 1));
        $ah = max(1, (int) ($tile->get('field_h')->value ?: 1));

        // Quick intersection test vs viewport.
        $ax2 = $ax + $aw - 1;
        $ay2 = $ay + $ah - 1;
        $intersects =
          ($ax <= $xmax) && ($ax2 >= $xmin) &&
          ($ay <= $ymax) && ($ay2 >= $ymin);

        if (!$intersects) continue;

        $cover = $this->buildTileCoverUrl($tile, $cache);

        $obj_preview = null;
        if ($tile->hasField('field_object_reference') && !$tile->get('field_object_reference')->isEmpty()) {
          $obj_node = $tile->get('field_object_reference')->entity;
          if ($obj_node) {
            $cache->addCacheableDependency($obj_node);
            $obj_preview = $this->buildObjectPreview($obj_node, $cache);
          }
        }

        $display_mode = $this->readStringField($tile, 'field_tile_display_mode', 'cover');
        $canonical = $this->readBoolFieldDefault($tile, 'field_is_canonical', true);
        $flippable = $this->readBoolField($tile, 'field_flippable', false);
        $ddt = $this->readBoolField($tile, 'field_ddt', false);

        $hasInteractiveMode = in_array($display_mode, ['graffiti_wall'], TRUE);

        // Song/layout media tiles are playable surfaces, not flip cards.
        // Interactive utility tiles, such as graffiti_wall, may flip even without
        // cover/object content because the UI itself is the content.
        if (in_array($display_mode, ['song', 'newsletter', 'media_tile', 'video_tile', 'graffiti_wall'], TRUE)) {
          $flippable = false;
        }
        elseif ($hasInteractiveMode) {
          $flippable = true;
        }

        $anchors[] = [
          'x' => $ax,
          'y' => $ay,
          'w' => $aw,
          'h' => $ah,
          'cover' => $cover,
          'display_mode' => $display_mode,
          'canonical' => $canonical,
          'flippable' => $flippable,
          'ddt' => $ddt,
          'object' => $obj_preview,
        ];
      }
    }

    $response = new CacheableJsonResponse([
      'viewport' => [
        'x' => (int) $xmin,
        'y' => (int) $ymin,
        'w' => (int) (($xmax - $xmin) + 1),
        'h' => (int) (($ymax - $ymin) + 1),
        'tile_px' => self::TILE_PX,
      ],
      'anchors' => $anchors,
    ]);

    $response->addCacheableDependency($cache);
    return $response;
  }

  /**
   * GET /api/world/cell?x=&y=&full=1
   */
  public function cell(Request $request): JsonResponse {
    if ($denied = $this->requireBetaAccess()) {
      return $denied;
    }

    $z = $this->intOptionalParam($request, 'z', 10);
    $x = $this->intParam($request, 'x');
    $y = $this->intParam($request, 'y');
    $full = $this->boolParam($request, 'full', false);

    $storage = $this->entityTypeManager->getStorage('node');

    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'tile')
      ->condition('status', 1)
      ->condition('field_z', $z)
      ->condition('field_x', $x)
      ->condition('field_y', $y)
      ->range(0, 1)
      ->execute();

    $cache = new CacheableMetadata();
    $cache->setCacheContexts(['url.query_args']);

    // Prevent stale empty-cell responses after adding/publishing tiles.
    $cache->addCacheTags(['node_list:tile']);
    $cache->setCacheMaxAge(0);

    if (!$nids) {
      $res = new CacheableJsonResponse([
        'z' => $z,
        'x' => $x,
        'y' => $y,
        'occupied' => false,
        'role' => 'empty',
      ]);
      $res->addCacheableDependency($cache);
      return $res;
    }

    $tile = $storage->load(reset($nids));
    $cache->addCacheableDependency($tile);

    $role = (string) ($tile->get('field_role')->value ?? 'anchor');

    // Infra tiles (canvas-rendered).
    if ($role === 'ybr' || $role === 'road') {
      $res = new CacheableJsonResponse([
        'z' => $z,
        'x' => $x,
        'y' => $y,
        'occupied' => true,
        'role' => $role,
        'ddt' => true,
        'flippable' => false,
      ]);
      $res->addCacheableDependency($cache);
      return $res;
    }

    // Resolve anchor if this is an occupied tile pointing to an anchor.
    $anchor = $tile;
    if ($role === 'occupied') {
      if ($tile->hasField('field_anchor_ref') && !$tile->get('field_anchor_ref')->isEmpty()) {
        $a = $tile->get('field_anchor_ref')->entity;
        if ($a) {
          $anchor = $a;
          $cache->addCacheableDependency($anchor);
        }
      }
    }

    $ax = (int) ($anchor->get('field_x')->value ?? $x);
    $ay = (int) ($anchor->get('field_y')->value ?? $y);
    $aw = max(1, (int) ($anchor->get('field_w')->value ?: 1));
    $ah = max(1, (int) ($anchor->get('field_h')->value ?: 1));

    $cover = $this->buildTileCoverUrl($anchor, $cache);

    $obj = null;
    if ($anchor->hasField('field_object_reference') && !$anchor->get('field_object_reference')->isEmpty()) {
      $obj_node = $anchor->get('field_object_reference')->entity;
      if ($obj_node) {
        $cache->addCacheableDependency($obj_node);
        $obj = $full
          ? $this->buildObjectFull($obj_node, $cache)
          : $this->buildObjectPreview($obj_node, $cache);
      }
    }

// Determine if this anchor tile allows flipping.
$display_mode = $this->readStringField($anchor, 'field_tile_display_mode', 'cover');
$canonical = $this->readBoolFieldDefault($anchor, 'field_is_canonical', true);
$allowFlip = $this->readBoolField($anchor, 'field_flippable', false);
$hasInteractiveMode = in_array($display_mode, ['graffiti_wall'], TRUE);
$ddt = ($role === 'road' || $role === 'ybr') || $this->readBoolField($anchor, 'field_ddt', false);

    $res = new CacheableJsonResponse([
      'z' => $z,
      'x' => $x,
      'y' => $y,
      'occupied' => true,
      'role' => $role,

      'anchor' => [
        'x' => $ax,
        'y' => $ay,
        'w' => $aw,
        'h' => $ah,
        'url' => "/w/{$z}/{$ax}/{$ay}",
      ],

      'cover' => $cover,
      'display_mode' => $display_mode,
      'canonical' => $canonical,

      // IMPORTANT: "object" is preview OR full depending on ?full=1.
      'object' => $obj,

      // Convenience flags for UI.
      'ddt' => $ddt,

      // Only flippable if:
      // 1) field_flippable is true
      // 2) tile actually has content
      // 3) this is not a dedicated song layout tile
      'flippable' => (in_array($display_mode, ['song', 'newsletter', 'media_tile', 'video_tile'], TRUE)) ? false : ($allowFlip && ((bool) $cover || (bool) $obj || $hasInteractiveMode)),
    ]);

    $res->addCacheableDependency($cache);
    return $res;
  }

  /**
   * GET /api/world/infra?z=&xmin=&xmax=&ymin=&ymax=
   */
  public function infra(Request $request): JsonResponse {
    if ($denied = $this->requireBetaAccess()) {
      return $denied;
    }

    $z = $this->intOptionalParam($request, 'z', 10);
    $xmin = $this->intParam($request, 'xmin');
    $xmax = $this->intParam($request, 'xmax');
    $ymin = $this->intParam($request, 'ymin');
    $ymax = $this->intParam($request, 'ymax');

    $storage = $this->entityTypeManager->getStorage('node');

    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'tile')
      ->condition('status', 1)
      ->condition('field_z', $z)
      ->condition('field_x', [$xmin, $xmax], 'BETWEEN')
      ->condition('field_y', [$ymin, $ymax], 'BETWEEN')
      ->condition('field_role', ['ybr', 'road'], 'IN')
      ->execute();

    $cache = new CacheableMetadata();
    $cache->setCacheContexts(['url.query_args']);

    // Road/YBR placement is tile-backed; avoid stale infra during layout work.
    $cache->addCacheTags(['node_list:tile']);
    $cache->setCacheMaxAge(0);

    if (!$nids) {
      $res = new CacheableJsonResponse([]);
      $res->addCacheableDependency($cache);
      return $res;
    }

    $tiles = $storage->loadMultiple($nids);
    $data = [];

    foreach ($tiles as $tile) {
      $cache->addCacheableDependency($tile);
      $data[] = [
        'x' => (int) $tile->get('field_x')->value,
        'y' => (int) $tile->get('field_y')->value,
        'role' => (string) $tile->get('field_role')->value,
      ];
    }

    $res = new CacheableJsonResponse($data);
    $res->addCacheableDependency($cache);
    return $res;
  }


/**
   * GET /api/world/gates?z=&xmin=&xmax=&ymin=&ymax=
   */
  public function gates(Request $request): JsonResponse {
    if ($denied = $this->requireBetaAccess()) {
      return $denied;
    }

    $z = $this->intOptionalParam($request, 'z', 10);
    $xmin = $this->intParam($request, 'xmin');
    $xmax = $this->intParam($request, 'xmax');
    $ymin = $this->intParam($request, 'ymin');
    $ymax = $this->intParam($request, 'ymax');

    $storage = $this->entityTypeManager->getStorage('node');

    $nids = $storage->getQuery()
    ->accessCheck(TRUE)
    ->condition('type', 'biome_gate')
    ->condition('status', 1)
    ->condition('field_z', $z)
    ->condition('field_x', $xmax, '<=')
    ->condition('field_y', $ymax, '<=')
    ->execute();

    $cache = new CacheableMetadata();
    $cache->setCacheContexts(['url.query_args']);

    // Important for newly-created biome_gate nodes.
    // Without this, an old viewport response can stay cached and miss a new gate.
    $cache->addCacheTags(['node_list:biome_gate']);

    // During active layout work, disable endpoint caching.
    // Later we can relax this to a short max-age if needed.
    $cache->setCacheMaxAge(0);

    $out = [];

    if ($nids) {
      $nodes = $storage->loadMultiple($nids);

      foreach ($nodes as $node) {
        $cache->addCacheableDependency($node);

        $x = (int) ($node->get('field_x')->value ?? 0);
        $y = (int) ($node->get('field_y')->value ?? 0);
        $w = max(1, (int) ($node->get('field_w')->value ?: 2));
        $h = max(1, (int) ($node->get('field_h')->value ?: 2));

        $x2 = $x + $w - 1;
        $y2 = $y + $h - 1;

        // viewport intersection
        if ($x > $xmax || $x2 < $xmin || $y > $ymax || $y2 < $ymin) {
          continue;
        }

        $src = null;

        if ($node->hasField('field_gate_image') && !$node->get('field_gate_image')->isEmpty()) {
          $media = $node->get('field_gate_image')->entity;
          if ($media) {
            $cache->addCacheableDependency($media);

            if ($media->hasField('field_media_image') && !$media->get('field_media_image')->isEmpty()) {
              $file = $media->get('field_media_image')->entity;
              if ($file) {
                $cache->addCacheableDependency($file);
                $src = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
              }
            }
          }
        }

        $out[] = [
          'x' => $x,
          'y' => $y,
          'w' => $w,
          'h' => $h,
          'biome' => (string) ($node->get('field_biome_key')->value ?? ''),
          'direction' => (string) ($node->get('field_direction')->value ?? ''),
          'src' => $src,
        ];
      }
    }

    $res = new CacheableJsonResponse($out);
    $res->addCacheableDependency($cache);
    return $res;
  }

  /**
   * GET /api/world/resolve-gate?key=
   */
  public function resolveGate(Request $request): JsonResponse {
    if ($denied = $this->requireBetaAccess()) {
      return $denied;
    }

    $key = trim((string) $request->query->get('key', ''));

    if ($key === '') {
      return new JsonResponse(['found' => false], 200);
    }

    $storage = $this->entityTypeManager->getStorage('node');

    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'biome_gate')
      ->condition('status', 1)
      ->condition('field_biome_key', $key)
      ->range(0, 1)
      ->execute();

    if (!$nids) {
      return new JsonResponse(['found' => false], 200);
    }

    $node = $storage->load(reset($nids));

    $x = (int) ($node->get('field_x')->value ?? 0);
    $y = (int) ($node->get('field_y')->value ?? 0);
    $z = (int) ($node->get('field_z')->value ?? 10);

    return new JsonResponse([
      'found' => true,
      'anchor' => [
        'x' => $x,
        'y' => $y,
        'w' => max(1, (int) ($node->get('field_w')->value ?: 2)),
        'h' => max(1, (int) ($node->get('field_h')->value ?: 2)),
        'url' => "/w/{$z}/{$x}/{$y}",
      ],
    ]);
  }
  /**
   * GET /api/world/resolve?bundle=&slug=
   */
  public function resolve(Request $request): JsonResponse {
    if ($denied = $this->requireBetaAccess()) {
      return $denied;
    }

    $bundle = trim((string) $request->query->get('bundle', ''));
    $slug = trim((string) $request->query->get('slug', ''));
    $z = $this->intOptionalParam($request, 'z', 10);

    if ($bundle === '' || $slug === '') {
      return new JsonResponse(['found' => false], 200);
    }

    $candidates = [
      "/world/{$bundle}/{$slug}",
      "/{$bundle}/{$slug}",
    ];

    $obj_nid = null;
    foreach ($candidates as $alias) {
      $internal = $this->aliasManager->getPathByAlias($alias);
      if (!is_string($internal)) continue;
      if (preg_match('#^/node/(\d+)$#', $internal, $m)) {
        $obj_nid = (int) $m[1];
        break;
      }
    }

    if (!$obj_nid) {
      return new JsonResponse(['found' => false], 200);
    }

    $storage = $this->entityTypeManager->getStorage('node');

    $tile_nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'tile')
      ->condition('status', 1)
      ->condition('field_z', $z)
      ->condition('field_role', 'anchor')
      ->condition('field_object_reference', $obj_nid)
      ->execute();

    if (!$tile_nids) {
      return new JsonResponse(['found' => false, 'nid' => $obj_nid], 200);
    }

    $tiles = $storage->loadMultiple($tile_nids);
    $tile = $this->pickBestObjectTile($tiles);

    if (!$tile) {
      return new JsonResponse(['found' => false, 'nid' => $obj_nid], 200);
    }

    $cache = new CacheableMetadata();
    $cache->setCacheContexts(['url.query_args']);
    $cache->addCacheableDependency($tile);

    $cover = $this->buildTileCoverUrl($tile, $cache);
    $display_mode = $this->readStringField($tile, 'field_tile_display_mode', 'cover');
    $canonical = $this->readBoolFieldDefault($tile, 'field_is_canonical', true);

    $obj = null;
    if ($tile->hasField('field_object_reference') && !$tile->get('field_object_reference')->isEmpty()) {
      $obj_node = $tile->get('field_object_reference')->entity;
      if ($obj_node) {
        $cache->addCacheableDependency($obj_node);
        $obj = $this->buildObjectPreview($obj_node, $cache);
      }
    }

    $res = new CacheableJsonResponse([
      'found' => true,
      'nid' => $obj_nid,
      'anchor' => [
        'x' => (int) $tile->get('field_x')->value,
        'y' => (int) $tile->get('field_y')->value,
        'w' => max(1, (int) ($tile->get('field_w')->value ?: 1)),
        'h' => max(1, (int) ($tile->get('field_h')->value ?: 1)),
        'cover' => $cover,
        'display_mode' => $display_mode,
        'canonical' => $canonical,
        'object' => $obj,
      ],
    ]);

    $res->addCacheableDependency($cache);
    return $res;
  }


  public function newsletter(Request $request): JsonResponse {
    if ($deny = $this->requireBetaAccess()) {
      return $deny;
    }

    $key = trim((string) $request->query->get('key', 'earworm'));
    $date = trim((string) $request->query->get('date', ''));

    if ($key === '') {
      return new JsonResponse(['found' => false, 'error' => 'missing_key'], 200);
    }

    $storage = $this->entityTypeManager->getStorage('node');

    $edition_query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'newsletter_edition')
      ->condition('status', 1)
      ->condition('field_newsletter_key', $key);

    if ($date !== '') {
      $edition_query->condition('field_newsletter_date', $date);
    }

    $edition_nids = $edition_query
      ->sort('field_newsletter_date', 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, 1)
      ->execute();

    if (!$edition_nids) {
      return new JsonResponse(['found' => false, 'key' => $key, 'date' => $date ?: null], 200);
    }

    $edition_nid = (int) reset($edition_nids);

    $tile_nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'tile')
      ->condition('status', 1)
      ->condition('field_role', 'anchor')
      ->condition('field_object_reference', $edition_nid)
      ->sort('nid', 'DESC')
      ->range(0, 10)
      ->execute();

    if (!$tile_nids) {
      return new JsonResponse([
        'found' => false,
        'key' => $key,
        'date' => $date ?: null,
        'edition_nid' => $edition_nid,
      ], 200);
    }

    $tiles = $storage->loadMultiple($tile_nids);
    $tile = null;

    // Prefer the live/newsletter display tile when present.
    foreach ($tiles as $candidate) {
      if (
        $candidate->hasField('field_tile_display_mode') &&
        !$candidate->get('field_tile_display_mode')->isEmpty() &&
        (string) $candidate->get('field_tile_display_mode')->value === 'newsletter'
      ) {
        $tile = $candidate;
        break;
      }
    }

    if (!$tile) {
      $tile = reset($tiles);
    }

    $x = (int) $tile->get('field_x')->value;
    $y = (int) $tile->get('field_y')->value;
    $z = $tile->hasField('field_z') && !$tile->get('field_z')->isEmpty()
      ? (int) $tile->get('field_z')->value
      : 10;

    $w = $tile->hasField('field_w') && !$tile->get('field_w')->isEmpty()
      ? max(1, (int) $tile->get('field_w')->value)
      : 1;

    $h = $tile->hasField('field_h') && !$tile->get('field_h')->isEmpty()
      ? max(1, (int) $tile->get('field_h')->value)
      : 1;

    return new JsonResponse([
      'found' => true,
      'key' => $key,
      'date' => $date ?: null,
      'edition_nid' => $edition_nid,
      'anchor' => [
        'x' => $x,
        'y' => $y,
        'w' => $w,
        'h' => $h,
        'url' => "/w/{$z}/{$x}/{$y}",
      ],
    ], 200);
  }

  public function deleteTilePlacement(Request $request): JsonResponse {
    if ($request->getMethod() === 'OPTIONS') {
      return new JsonResponse(['ok' => true], 200);
    }

    if ($deny = $this->requireBetaAccess()) {
      return $deny;
    }

    $account = \Drupal::currentUser();
    if (!in_array('administrator', $account->getRoles(), TRUE)) {
      return new JsonResponse([
        'ok' => false,
        'error' => 'builder_access_required',
        'message' => 'Administrator access is required to delete world tiles.',
      ], 403);
    }

    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      return new JsonResponse([
        'ok' => false,
        'error' => 'invalid_json',
      ], 400);
    }

    $z = (int) ($payload['z'] ?? 10);
    $x = (int) ($payload['x'] ?? -1);
    $y = (int) ($payload['y'] ?? -1);

    if ($z < 0 || $x < 0 || $y < 0 || $x > 1023 || $y > 1023) {
      return new JsonResponse([
        'ok' => false,
        'error' => 'invalid_coordinates',
        'message' => 'Expected z >= 0 and x/y between 0 and 1023.',
      ], 400);
    }

    $storage = $this->entityTypeManager->getStorage('node');

    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'tile')
      ->condition('field_z', $z)
      ->condition('field_x', $x)
      ->condition('field_y', $y)
      ->range(0, 1)
      ->execute();

    if (!$nids) {
      return new JsonResponse([
        'ok' => false,
        'error' => 'tile_not_found',
        'message' => 'No tile exists at that coordinate.',
        'z' => $z,
        'x' => $x,
        'y' => $y,
      ], 404);
    }

    $tile = $storage->load(reset($nids));
    if (!$tile) {
      return new JsonResponse([
        'ok' => false,
        'error' => 'tile_not_found',
      ], 404);
    }

    $deleted = [
      'id' => (int) $tile->id(),
      'title' => $tile->label(),
      'z' => $z,
      'x' => $x,
      'y' => $y,
    ];

    $tile->delete();

    return new JsonResponse([
      'ok' => true,
      'action' => 'deleted',
      'tile' => $deleted,
    ], 200);
  }

  public function loadTilePlacement(Request $request): JsonResponse {
    if ($request->getMethod() === 'OPTIONS') {
      return new JsonResponse(['ok' => true], 200);
    }

    if ($deny = $this->requireBetaAccess()) {
      return $deny;
    }

    $account = \Drupal::currentUser();
    if (!in_array('administrator', $account->getRoles(), TRUE)) {
      return new JsonResponse([
        'ok' => false,
        'error' => 'builder_access_required',
        'message' => 'Administrator access is required to edit world tiles.',
      ], 403);
    }

    $z = (int) $request->query->get('z', 10);
    $x = (int) $request->query->get('x', -1);
    $y = (int) $request->query->get('y', -1);

    if ($z < 0 || $x < 0 || $y < 0 || $x > 1023 || $y > 1023) {
      return new JsonResponse([
        'ok' => false,
        'error' => 'invalid_coordinates',
        'message' => 'Expected z >= 0 and x/y between 0 and 1023.',
      ], 400);
    }

    $storage = $this->entityTypeManager->getStorage('node');

    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'tile')
      ->condition('field_z', $z)
      ->condition('field_x', $x)
      ->condition('field_y', $y)
      ->range(0, 1)
      ->execute();

    if (!$nids) {
      return new JsonResponse([
        'ok' => true,
        'found' => false,
        'z' => $z,
        'x' => $x,
        'y' => $y,
      ], 200);
    }

    $tile = $storage->load(reset($nids));
    if (!$tile) {
      return new JsonResponse([
        'ok' => true,
        'found' => false,
        'z' => $z,
        'x' => $x,
        'y' => $y,
      ], 200);
    }

    $role = $tile->hasField('field_role') ? (string) ($tile->get('field_role')->value ?? 'anchor') : 'anchor';

    // If an occupied footprint tile is clicked, edit the anchor tile.
    if ($role === 'occupied' && $tile->hasField('field_anchor_ref') && !$tile->get('field_anchor_ref')->isEmpty()) {
      $anchor = $tile->get('field_anchor_ref')->entity;
      if ($anchor) {
        $tile = $anchor;
        $role = $tile->hasField('field_role') ? (string) ($tile->get('field_role')->value ?? 'anchor') : 'anchor';
      }
    }

    $tx = $tile->hasField('field_x') ? (int) ($tile->get('field_x')->value ?? $x) : $x;
    $ty = $tile->hasField('field_y') ? (int) ($tile->get('field_y')->value ?? $y) : $y;
    $tz = $tile->hasField('field_z') ? (int) ($tile->get('field_z')->value ?? $z) : $z;
    $tw = $tile->hasField('field_w') ? max(1, (int) ($tile->get('field_w')->value ?: 1)) : 1;
    $th = $tile->hasField('field_h') ? max(1, (int) ($tile->get('field_h')->value ?: 1)) : 1;

    $display_mode = $tile->hasField('field_tile_display_mode')
      ? (string) ($tile->get('field_tile_display_mode')->value ?? '')
      : '';

    $tile_key = $tile->hasField('field_tile_key')
      ? (string) ($tile->get('field_tile_key')->value ?? "{$tz}:{$tx}:{$ty}")
      : "{$tz}:{$tx}:{$ty}";

    $flippable = $tile->hasField('field_flippable') ? (bool) $tile->get('field_flippable')->value : false;
    $ddt = $tile->hasField('field_ddt') ? (bool) $tile->get('field_ddt')->value : false;
    $canonical = $tile->hasField('field_is_canonical') ? (bool) $tile->get('field_is_canonical')->value : false;

    $target_id = 0;
    $object_bundle = '';
    $object_title = '';
    $object_path = '';

    if ($tile->hasField('field_object_reference') && !$tile->get('field_object_reference')->isEmpty()) {
      $obj = $tile->get('field_object_reference')->entity;
      if ($obj) {
        $target_id = (int) $obj->id();
        $object_bundle = (string) $obj->bundle();
        $object_title = (string) $obj->label();
        $object_path = $this->aliasManager->getAliasByPath('/node/' . $obj->id());
      }
    }

    $builder_type = 'custom_tile';
    if ($display_mode === 'newsletter' || $object_bundle === 'newsletter_edition') {
      $builder_type = 'newsletter';
    }
    elseif ($display_mode === 'song' || $object_bundle === 'song') {
      $builder_type = 'song';
    }
    elseif ($display_mode === 'chat') {
      $builder_type = 'chat';
    }
    elseif ($display_mode === 'gate' || $object_bundle === 'biome_gate') {
      $builder_type = 'biome_gate';
    }
    elseif ($object_bundle === 'artist') {
      $builder_type = 'artist';
    }
    elseif ($object_bundle === 'place') {
      $builder_type = 'place';
    }
    elseif ($object_bundle === 'custom_tile') {
      $builder_type = 'custom_tile';
    }

    $search_bundle = $object_bundle ?: ($builder_type === 'chat' ? 'custom_tile' : $builder_type);

    $row = [
      'key' => "{$tz}:{$tx}:{$ty}",
      'builder_type' => $builder_type,
      'search_bundle' => $search_bundle,
      'title' => (string) $tile->label(),
      'tile_key' => $tile_key,
      'z' => $tz,
      'x' => $tx,
      'y' => $ty,
      'w' => $tw,
      'h' => $th,
      'role' => $role,
      'object_ref' => $target_id ? "node:{$target_id}" : '',
      'target_id' => $target_id ?: '',
      'object_title' => $object_title,
      'object_path' => $object_path,
      'bundle' => $object_bundle,
      'tile_display_mode' => $display_mode,
      'flippable' => $flippable ? 1 : 0,
      'ddt' => $ddt ? 1 : 0,
      'is_canonical' => $canonical ? 1 : 0,
      'cms_status' => 'loaded',
      'cms_action' => 'loaded',
      'cms_tile_id' => (int) $tile->id(),
      'cms_message' => 'Loaded existing tile',
    ];

    return new JsonResponse([
      'ok' => true,
      'found' => true,
      'tile' => [
        'id' => (int) $tile->id(),
        'title' => (string) $tile->label(),
        'url' => "/w/{$tz}/{$tx}/{$ty}",
      ],
      'row' => $row,
    ], 200);
  }

  public function saveTilePlacement(Request $request): JsonResponse {
    if ($request->getMethod() === 'OPTIONS') {
      return new JsonResponse(['ok' => true], 200);
    }

    if ($deny = $this->requireBetaAccess()) {
      return $deny;
    }

    $account = \Drupal::currentUser();
    if (!in_array('administrator', $account->getRoles(), TRUE)) {
      return new JsonResponse([
        'ok' => false,
        'error' => 'builder_access_required',
        'message' => 'Administrator access is required to place world tiles.',
      ], 403);
    }

    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      return new JsonResponse([
        'ok' => false,
        'error' => 'invalid_json',
      ], 400);
    }

    $profiles = [
      'song' => [
        'object_bundle' => 'song',
        'search_bundles' => ['song'],
        'display_modes' => ['song'],
        'requires_object' => TRUE,
        'default_w' => 1,
        'default_h' => 1,
      ],
      'newsletter' => [
        'object_bundle' => 'newsletter_edition',
        'search_bundles' => ['newsletter_edition'],
        'display_modes' => ['newsletter'],
        'requires_object' => TRUE,
        'default_w' => 2,
        'default_h' => 2,
      ],
      'artist' => [
        'object_bundle' => 'artist',
        'search_bundles' => ['artist'],
        'display_modes' => ['media_tile'],
        'requires_object' => TRUE,
        'default_w' => 1,
        'default_h' => 1,
      ],
      'place' => [
        'object_bundle' => 'place',
        'search_bundles' => ['place'],
        'display_modes' => ['media_tile'],
        'requires_object' => TRUE,
        'default_w' => 1,
        'default_h' => 1,
      ],
      'custom_tile' => [
        'object_bundle' => 'custom_tile',
        'search_bundles' => ['custom_tile'],
        'display_modes' => ['media_tile'],
        'requires_object' => TRUE,
        'default_w' => 2,
        'default_h' => 2,
      ],
      'chat' => [
        'object_bundle' => 'custom_tile',
        'search_bundles' => ['custom_tile'],
        'display_modes' => ['chat'],
        'requires_object' => FALSE,
        'default_w' => 2,
        'default_h' => 2,
      ],
      'biome_gate' => [
        'object_bundle' => 'biome_gate',
        'search_bundles' => ['biome_gate'],
        'display_modes' => ['gate'],
        'requires_object' => TRUE,
        'default_w' => 1,
        'default_h' => 4,
      ],
      'road' => [
        'object_bundle' => '',
        'search_bundles' => [],
        'display_modes' => ['none'],
        'requires_object' => FALSE,
        'default_w' => 1,
        'default_h' => 1,
      ],
    ];

    $builder_type = trim((string) ($payload['builder_type'] ?? ''));
    if ($builder_type === '' || !isset($profiles[$builder_type])) {
      return new JsonResponse([
        'ok' => false,
        'error' => 'invalid_builder_type',
        'allowed_builder_types' => array_keys($profiles),
      ], 400);
    }

    $profile = $profiles[$builder_type];

    $z = (int) ($payload['z'] ?? 10);
    $x = (int) ($payload['x'] ?? -1);
    $y = (int) ($payload['y'] ?? -1);
    $w = max(1, min(64, (int) ($payload['w'] ?? $profile['default_w'])));
    $h = max(1, min(64, (int) ($payload['h'] ?? $profile['default_h'])));

    if ($z < 0 || $x < 0 || $y < 0 || $x > 1023 || $y > 1023) {
      return new JsonResponse([
        'ok' => false,
        'error' => 'invalid_coordinates',
        'message' => 'Expected z >= 0 and x/y between 0 and 1023.',
      ], 400);
    }

    if (($x + $w - 1) > 1023 || ($y + $h - 1) > 1023) {
      return new JsonResponse([
        'ok' => false,
        'error' => 'tile_out_of_bounds',
      ], 400);
    }

    $allowed_roles = ['anchor', 'occupied', 'plaza', 'empty', 'road'];
    $role = trim((string) ($payload['role'] ?? 'anchor'));
    if (!in_array($role, $allowed_roles, TRUE)) {
      return new JsonResponse([
        'ok' => false,
        'error' => 'invalid_role',
        'allowed_roles' => $allowed_roles,
      ], 400);
    }

    if ($builder_type === 'road') {
      $role = 'road';
      $w = 1;
      $h = 1;
      $payload['tile_display_mode'] = 'none';
      $payload['flippable'] = 0;
      $payload['ddt'] = 1;
      $payload['is_canonical'] = 0;
      $payload['target_id'] = 0;
      $payload['object_ref'] = '';
    }

    $display_mode = trim((string) ($payload['tile_display_mode'] ?? $profile['display_modes'][0]));
    if (!in_array($display_mode, $profile['display_modes'], TRUE)) {
      return new JsonResponse([
        'ok' => false,
        'error' => 'invalid_display_mode',
        'allowed_display_modes' => $profile['display_modes'],
      ], 400);
    }

    $target_id = (int) ($payload['target_id'] ?? 0);
    if (!$target_id && !empty($payload['object_ref']) && preg_match('/node:(\d+)/', (string) $payload['object_ref'], $m)) {
      $target_id = (int) $m[1];
    }

    if ($profile['requires_object'] && !$target_id) {
      return new JsonResponse([
        'ok' => false,
        'error' => 'missing_target_id',
        'message' => 'This builder type requires a referenced object.',
      ], 400);
    }

    $storage = $this->entityTypeManager->getStorage('node');

    $target_node = null;
    if ($target_id) {
      $target_node = $storage->load($target_id);
      if (!$target_node || $target_node->bundle() === 'tile') {
        return new JsonResponse([
          'ok' => false,
          'error' => 'invalid_target',
          'message' => 'Referenced object was not found.',
        ], 400);
      }

      if (!in_array($target_node->bundle(), $profile['search_bundles'], TRUE)) {
        return new JsonResponse([
          'ok' => false,
          'error' => 'target_bundle_mismatch',
          'expected_bundles' => $profile['search_bundles'],
          'actual_bundle' => $target_node->bundle(),
        ], 400);
      }
    }

    $tile_key = trim((string) ($payload['tile_key'] ?? "{$z}:{$x}:{$y}"));
    if ($tile_key === '') {
      $tile_key = "{$z}:{$x}:{$y}";
    }

    $title = trim((string) ($payload['title'] ?? ''));
    if ($title === '') {
      $target_label = $target_node ? $target_node->label() : $builder_type;
      $title = "{$builder_type} {$target_label} @ {$z}:{$x}:{$y}";
    }

    $existing = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'tile')
      ->condition('field_z', $z)
      ->condition('field_x', $x)
      ->condition('field_y', $y)
      ->range(0, 1)
      ->execute();

    $action = 'created';
    if ($existing) {
      $tile = $storage->load(reset($existing));
      $action = 'updated';
    }
    else {
      $tile = $storage->create([
        'type' => 'tile',
        'status' => 1,
      ]);
    }

    $tile->setTitle($title);

    $field_values = [
      'field_z' => $z,
      'field_x' => $x,
      'field_y' => $y,
      'field_w' => $w,
      'field_h' => $h,
      'field_role' => $role,
      'field_tile_key' => $tile_key,
      'field_tile_display_mode' => $display_mode,
      'field_flippable' => !empty($payload['flippable']) ? 1 : 0,
      'field_ddt' => !empty($payload['ddt']) ? 1 : 0,
      'field_is_canonical' => !empty($payload['is_canonical']) ? 1 : 0,
    ];

    foreach ($field_values as $field_name => $value) {
      if ($tile->hasField($field_name)) {
        $tile->set($field_name, $value);
      }
    }

    if ($tile->hasField('field_object_reference')) {
      $tile->set('field_object_reference', $target_id ? ['target_id' => $target_id] : []);
    }

    $tile->save();

    return new JsonResponse([
      'ok' => true,
      'action' => $action,
      'tile' => [
        'id' => (int) $tile->id(),
        'title' => $tile->label(),
        'z' => $z,
        'x' => $x,
        'y' => $y,
        'w' => $w,
        'h' => $h,
        'role' => $role,
        'target_id' => $target_id ?: null,
        'target_bundle' => $target_node ? $target_node->bundle() : null,
        'tile_display_mode' => $display_mode,
        'url' => "/w/{$z}/{$x}/{$y}",
      ],
    ], 200);
  }

  public function objectSearch(Request $request): JsonResponse {
    if ($deny = $this->requireBetaAccess()) {
      return $deny;
    }

    $allowed_bundles = [
      'song',
      'artist',
      'newsletter_edition',
      'place',
      'custom_tile',
      'biome_gate',
      'menu_object',
    ];

    $bundle = trim((string) $request->query->get('bundle', 'song'));
    $q = trim((string) $request->query->get('q', ''));
    $limit = (int) $request->query->get('limit', 12);
    $limit = max(1, min(25, $limit));

    if (!in_array($bundle, $allowed_bundles, TRUE)) {
      return new JsonResponse([
        'ok' => false,
        'error' => 'invalid_bundle',
        'allowed_bundles' => $allowed_bundles,
      ], 400);
    }

    if ($q === '') {
      return new JsonResponse([
        'ok' => true,
        'bundle' => $bundle,
        'q' => $q,
        'results' => [],
      ], 200);
    }

    $storage = $this->entityTypeManager->getStorage('node');

    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $bundle)
      ->condition('status', 1)
      ->range(0, $limit)
      ->sort('changed', 'DESC');

    if (ctype_digit($q)) {
      $query->condition('nid', (int) $q);
    }
    else {
      $query->condition('title', $q, 'CONTAINS');
    }

    $nids = $query->execute();

    if (!$nids) {
      return new JsonResponse([
        'ok' => true,
        'bundle' => $bundle,
        'q' => $q,
        'results' => [],
      ], 200);
    }

    $nodes = $storage->loadMultiple($nids);
    $results = [];

    foreach ($nodes as $node) {
      $results[] = [
        'id' => (int) $node->id(),
        'nid' => (int) $node->id(),
        'bundle' => (string) $node->bundle(),
        'title' => (string) $node->label(),
        'path' => $this->aliasManager->getAliasByPath('/node/' . $node->id()),
      ];
    }

    return new JsonResponse([
      'ok' => true,
      'bundle' => $bundle,
      'q' => $q,
      'results' => $results,
    ], 200);
  }

  private function requireBetaAccess(): ?JsonResponse {
    $account = \Drupal::currentUser();

    if ($account->isAnonymous()) {
      return new JsonResponse([
        'ok' => false,
        'error' => 'login_required',
        'message' => 'Sign in to access the EWRM beta.',
      ], 401);
    }

    $allowed = array_intersect($account->getRoles(), [
      'administrator',
      'beta_tester',
    ]);

    if (empty($allowed)) {
      return new JsonResponse([
        'ok' => false,
        'error' => 'beta_access_required',
        'message' => 'Beta access is required.',
      ], 403);
    }

    return null;
  }

  // ----------------------------
  // Object builders (Preview/Full)
  // ----------------------------


  /**
   * newsletter_edition is a wrapper. Its display/media data comes from
   * field_object_reference, usually a song.
   */
  private function displayNodeForObject($node, CacheableMetadata $cache) {
    if (!$node) return $node;

    if (
      (string) $node->bundle() === 'newsletter_edition' &&
      $node->hasField('field_object_reference') &&
      !$node->get('field_object_reference')->isEmpty()
    ) {
      $display = $node->get('field_object_reference')->entity;
      if ($display) {
        $cache->addCacheableDependency($display);
        return $display;
      }
    }

    return $node;
  }

  private function buildObjectCoverUrl($node, CacheableMetadata $cache): ?string {
    if (!$node) return null;

    foreach (['field_cover_override', 'field_cover'] as $field_name) {
      if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
        continue;
      }

      $media = $node->get($field_name)->entity;
      if (!$media) continue;
      $cache->addCacheableDependency($media);

      if (!$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
        continue;
      }

      $file = $media->get('field_media_image')->entity;
      if (!$file) continue;
      $cache->addCacheableDependency($file);

      return $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
    }

    return null;
  }

  private function buildEmbedCoverUrl(?string $url): ?string {
    if (!$url || !is_string($url)) return null;

    $parts = @parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) return null;

    $host = strtolower((string) $parts['host']);
    $path = (string) ($parts['path'] ?? '');

    $query = [];
    if (!empty($parts['query'])) {
      parse_str((string) $parts['query'], $query);
    }

    $video_id = null;

    if (str_contains($host, 'youtube.com')) {
      if (!empty($query['v']) && is_string($query['v'])) {
        $video_id = $query['v'];
      }
      elseif (preg_match('#/(embed|shorts)/([^/?#]+)#', $path, $m)) {
        $video_id = $m[2];
      }
    }
    elseif ($host === 'youtu.be' || str_ends_with($host, '.youtu.be')) {
      $video_id = trim($path, '/');
    }

    if (!$video_id || !preg_match('/^[A-Za-z0-9_-]{6,}$/', $video_id)) {
      return null;
    }

    return 'https://i.ytimg.com/vi/' . rawurlencode($video_id) . '/hqdefault.jpg';
  }

  private function buildObjectPreview($node, CacheableMetadata $cache): array {
    $cache->addCacheableDependency($node);

    $display_node = $this->displayNodeForObject($node, $cache);

    $base = [
      'id' => (int) $node->id(),
      'bundle' => (string) $node->bundle(),
      'slug' => $this->slugForNode((int) $node->id()),
      'title' => (string) $node->label(),
      'path' => $this->aliasManager->getAliasByPath('/node/' . $node->id()),
      'cover' => $this->buildEntityCoverUrl($display_node, $cache),
    ];

    $embed = $this->readFirstLinkUrl($display_node, ['field_embed_url', 'field_media_url']);
    $base['embed_url'] = $embed ?: null;
    $base['embed_start'] = $this->readInt($display_node, ['field_embed_start'], 0);

    if ($display_node && (int) $display_node->id() !== (int) $node->id()) {
      $display_preview = [
        'id' => (int) $display_node->id(),
        'bundle' => (string) $display_node->bundle(),
        'slug' => $this->slugForNode((int) $display_node->id()),
        'title' => (string) $display_node->label(),
        'path' => $this->aliasManager->getAliasByPath('/node/' . $display_node->id()),
        'cover' => $this->buildEntityCoverUrl($display_node, $cache),
        'embed_url' => $embed ?: null,
        'embed_start' => $this->readInt($display_node, ['field_embed_start'], 0),
      ];

      $base['display_object'] = $display_preview;

      if ((string) $display_node->bundle() === 'song') {
        $base['referenced_song'] = $display_preview;
      }
    }

    return $base;
  }



  private function buildObjectFull($node, CacheableMetadata $cache): array {
    $cache->addCacheableDependency($node);

    $display_node = $this->displayNodeForObject($node, $cache);

    $payload = $this->buildObjectPreview($node, $cache);

    $payload['description'] = $this->readDescription($display_node);
    $payload['embed_url'] = $this->readFirstLinkUrl($display_node, ['field_embed_url', 'field_media_url']) ?: null;
    $payload['embed_start'] = $this->readInt($display_node, ['field_embed_start'], 0);
    $payload['links'] = $this->readLinks($display_node, ['field_links']);

    if ($display_node && (int) $display_node->id() !== (int) $node->id()) {
      $display_full = [
        'id' => (int) $display_node->id(),
        'bundle' => (string) $display_node->bundle(),
        'slug' => $this->slugForNode((int) $display_node->id()),
        'title' => (string) $display_node->label(),
        'path' => $this->aliasManager->getAliasByPath('/node/' . $display_node->id()),
        'cover' => $this->buildEntityCoverUrl($display_node, $cache),
        'embed_url' => $this->readFirstLinkUrl($display_node, ['field_embed_url', 'field_media_url']) ?: null,
        'embed_start' => $this->readInt($display_node, ['field_embed_start'], 0),
        'description' => $this->readDescription($display_node),
        'links' => $this->readLinks($display_node, ['field_links']),
      ];

      $payload['display_object'] = $display_full;

      if ((string) $display_node->bundle() === 'song') {
        $payload['referenced_song'] = $display_full;
      }
    }

    return $payload;
  }



  // ----------------------------
  // Tile helpers
  // ----------------------------

  private function buildEntityCoverUrl($entity, CacheableMetadata $cache): ?string {
    if (!$entity->hasField('field_cover') || $entity->get('field_cover')->isEmpty()) {
      return null;
    }

    $media = $entity->get('field_cover')->entity;
    if (!$media) return null;
    $cache->addCacheableDependency($media);

    if (!$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
      return null;
    }

    $file = $media->get('field_media_image')->entity;
    if (!$file) return null;
    $cache->addCacheableDependency($file);

    return $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
  }

  private function buildTileCoverUrl($tile, CacheableMetadata $cache): ?string {
    if (!$tile) return null;

    foreach (['field_cover_override', 'field_cover'] as $field_name) {
      if (!$tile->hasField($field_name) || $tile->get($field_name)->isEmpty()) {
        continue;
      }

      $media = $tile->get($field_name)->entity;
      if (!$media) continue;
      $cache->addCacheableDependency($media);

      if (!$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
        continue;
      }

      $file = $media->get('field_media_image')->entity;
      if (!$file) continue;
      $cache->addCacheableDependency($file);

      return $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
    }

    if ($tile->hasField('field_object_reference') && !$tile->get('field_object_reference')->isEmpty()) {
      $object = $tile->get('field_object_reference')->entity;
      if ($object) {
        $cache->addCacheableDependency($object);
        $display_object = $this->displayNodeForObject($object, $cache);
        return $this->buildEntityCoverUrl($display_object, $cache);
      }
    }

    return null;
  }


  // ----------------------------
  // Field readers
  // ----------------------------

  private function readDescription($node): string {
    // Preferred: field_description (formatted long).
    if ($node->hasField('field_description') && !$node->get('field_description')->isEmpty()) {
      $item = $node->get('field_description')->first();
      $val = (string) ($item?->value ?? '');
      return trim($val);
    }

    // Fallback: body summary/value.
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $item = $node->get('body')->first();
      $val = (string) ($item?->summary ?: $item?->value ?: '');
      return trim($val);
    }

    return '';
  }

  private function readFirstLinkUrl($node, array $fieldNames): ?string {
    foreach ($fieldNames as $field) {
      if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
        $it = $node->get($field)->first();
        $uri = (string) ($it?->uri ?? '');
        $url = $this->normalizeLinkUri($uri);
        if ($url !== '') return $url;
      }
    }
    return null;
  }

  private function readInt($node, array $fieldNames, int $default = 0): int {
    foreach ($fieldNames as $field) {
      if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
        $v = $node->get($field)->value;
        if ($v === null || $v === '') continue;
        if (preg_match('/^-?\d+$/', (string) $v)) return (int) $v;
      }
    }
    return $default;
  }

  private function readLinks($node, array $fieldNames, ?CacheableMetadata $cache = null): array {
    foreach ($fieldNames as $field) {
      if (!$node->hasField($field) || $node->get($field)->isEmpty()) continue;

      $out = [];

      foreach ($node->get($field) as $item) {
        $uri = (string) ($item?->uri ?? '');
        $url = $this->normalizeLinkUri($uri);
        if ($url === '') continue;

        $label = trim((string) ($item?->title ?? ''));

        $link = [
          'label' => $label,
          'url' => $url,
        ];

        $target = $this->resolveMenuLinkTarget($uri, $url, $cache);
        if ($target) {
          $link = array_merge($link, $target);
          $link['label'] = $label !== '' ? $label : (string) ($target['title'] ?? $link['label']);
        }

        $out[] = $link;
      }

      return $out;
    }

    return [];
  }

  private function resolveMenuLinkTarget(string $uri, string $normalizedUrl, ?CacheableMetadata $cache = null): ?array {
    $path = $this->menuLinkPath($uri, $normalizedUrl);
    if ($path === '') return null;

    $node = null;

    if (preg_match('#^/node/(\d+)$#', $path, $m)) {
      $node = $this->entityTypeManager->getStorage('node')->load((int) $m[1]);
    }
    elseif (preg_match('#^/world/w/(\d+)/(\d+)/(\d+)$#', $path, $m) || preg_match('#^/w/(\d+)/(\d+)/(\d+)$#', $path, $m)) {
      $node = $this->loadTileByCoords((int) $m[1], (int) $m[2], (int) $m[3]);
    }
    else {
      $candidatePaths = [$path];

      if (str_starts_with($path, '/world/')) {
        $candidatePaths[] = substr($path, strlen('/world')) ?: '/';
      }
      else {
        $candidatePaths[] = '/world' . $path;
      }

      foreach (array_unique($candidatePaths) as $candidatePath) {
        $internal = $this->aliasManager->getPathByAlias($candidatePath);
        if (is_string($internal) && preg_match('#^/node/(\d+)$#', $internal, $m)) {
          $node = $this->entityTypeManager->getStorage('node')->load((int) $m[1]);
          if ($node) break;
        }
      }
    }

    if (!$node || !$node->isPublished()) return null;

    if ($cache) {
      $cache->addCacheableDependency($node);
    }

    if ($node->bundle() === 'tile') {
      return $this->buildMenuLinkTargetFromTile($node, $cache);
    }

    return $this->buildMenuLinkTargetFromObject($node, $cache, null);
  }

  private function menuLinkPath(string $uri, string $normalizedUrl): string {
    $raw = trim($uri);

    if (str_starts_with($raw, 'entity:node/')) {
      $nid = (int) substr($raw, strlen('entity:node/'));
      return $nid > 0 ? "/node/{$nid}" : '';
    }

    if (str_starts_with($raw, 'internal:')) {
      $raw = substr($raw, strlen('internal:'));
    }
    else {
      $raw = trim($normalizedUrl) !== '' ? trim($normalizedUrl) : $raw;
    }

    if ($raw === '') return '';

    if (preg_match('#^https?://#i', $raw)) {
      $parts = @parse_url($raw);
      if (!is_array($parts) || empty($parts['path'])) return '';
      $path = (string) $parts['path'];
    }
    else {
      $path = $raw;
    }

    if ($path === '') return '';
    if ($path[0] !== '/') $path = '/' . $path;

    return $path;
  }

  private function loadTileByCoords(int $z, int $x, int $y) {
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'tile')
      ->condition('status', 1)
      ->condition('field_z', $z)
      ->condition('field_x', $x)
      ->condition('field_y', $y)
      ->range(0, 1)
      ->execute();

    if (!$nids) return null;

    return $this->entityTypeManager->getStorage('node')->load(reset($nids));
  }

  private function buildMenuLinkTargetFromTile($tile, ?CacheableMetadata $cache = null): array {
    if ($cache) {
      $cache->addCacheableDependency($tile);
    }

    $z = (int) ($tile->get('field_z')->value ?? 10);
    $x = (int) ($tile->get('field_x')->value ?? 0);
    $y = (int) ($tile->get('field_y')->value ?? 0);
    $w = max(1, (int) ($tile->get('field_w')->value ?: 1));
    $h = max(1, (int) ($tile->get('field_h')->value ?: 1));

    $tileUrl = "/w/{$z}/{$x}/{$y}";

    $target = [
      'tile_id' => (int) $tile->id(),
      'tile_url' => $tileUrl,
      'url' => $tileUrl,
      'w' => $w,
      'h' => $h,
    ];

    $object = null;
    if ($tile->hasField('field_object_reference') && !$tile->get('field_object_reference')->isEmpty()) {
      $object = $tile->get('field_object_reference')->entity;
    }

    if ($object && $object->isPublished()) {
      if ($cache) {
        $cache->addCacheableDependency($object);
      }

      return array_merge(
        $target,
        $this->buildMenuLinkTargetFromObject($object, $cache, $tileUrl)
      );
    }

    $target['entity_id'] = (int) $tile->id();
    $target['bundle'] = (string) $tile->bundle();
    $target['title'] = (string) $tile->label();
    $target['path'] = $tileUrl;
    $target['cover'] = $cache ? $this->buildTileCoverUrl($tile, $cache) : null;
    $target['embed_url'] = null;
    $target['embed_start'] = 0;

    return $target;
  }

  private function buildMenuLinkTargetFromObject($object, ?CacheableMetadata $cache = null, ?string $overrideUrl = null): array {
    if ($cache) {
      $cache->addCacheableDependency($object);
    }

    $path = $this->aliasManager->getAliasByPath('/node/' . $object->id());
    $embed = $this->readFirstLinkUrl($object, ['field_embed_url', 'field_media_url']);
    $embedThumbnail = $embed ? $this->thumbnailFromEmbedUrl($embed) : null;
    $cover = $cache ? $this->buildEntityCoverUrl($object, $cache) : null;

    return [
      'entity_id' => (int) $object->id(),
      'bundle' => (string) $object->bundle(),
      'title' => trim((string) $object->label()),
      'path' => $path,
      'url' => $overrideUrl ?: $path,
      'cover' => $cover ?: $embedThumbnail,
      'thumbnail' => $embedThumbnail,
      'embed_url' => $embed ?: null,
      'embed_start' => $this->readInt($object, ['field_embed_start'], 0),
    ];
  }

  private function thumbnailFromEmbedUrl(string $url): ?string {
    $url = trim($url);
    if ($url === '') return null;

    $parts = @parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) return null;

    $host = strtolower((string) $parts['host']);
    $path = (string) ($parts['path'] ?? '');
    $query = (string) ($parts['query'] ?? '');

    // YouTube watch URL:
    // https://www.youtube.com/watch?v=CMyNMITOjro
    if (str_contains($host, 'youtube.com')) {
      parse_str($query, $q);
      $id = isset($q['v']) ? trim((string) $q['v']) : '';

      // YouTube Shorts:
      // https://www.youtube.com/shorts/VIDEO_ID
      if ($id === '' && preg_match('#^/shorts/([^/?#]+)#', $path, $m)) {
        $id = trim((string) $m[1]);
      }

      // YouTube embed:
      // https://www.youtube.com/embed/VIDEO_ID
      if ($id === '' && preg_match('#^/embed/([^/?#]+)#', $path, $m)) {
        $id = trim((string) $m[1]);
      }

      if ($id !== '' && preg_match('/^[A-Za-z0-9_-]{6,}$/', $id)) {
        return "https://img.youtube.com/vi/{$id}/hqdefault.jpg";
      }
    }

    // Short YouTube URL:
    // https://youtu.be/CMyNMITOjro
    if ($host === 'youtu.be' || str_ends_with($host, '.youtu.be')) {
      $id = trim($path, '/');
      if ($id !== '' && preg_match('/^[A-Za-z0-9_-]{6,}$/', $id)) {
        return "https://img.youtube.com/vi/{$id}/hqdefault.jpg";
      }
    }

    return null;
  }

  /**
   * Normalize Drupal link URIs to something Vue can use directly.
   * - "https://..." => same
   * - "internal:/path" => "/path"
   * - "entity:node/123" => alias for /node/123 (best effort)
   */
  private function normalizeLinkUri(string $uri): string {
    $uri = trim($uri);
    if ($uri === '') return '';

    if (preg_match('#^https?://#i', $uri)) {
      return $uri;
    }

    if (str_starts_with($uri, 'internal:')) {
      $path = substr($uri, strlen('internal:'));
      $path = $path === '' ? '/' : $path;
      return $path;
    }

    if (str_starts_with($uri, 'entity:node/')) {
      $nid = (int) substr($uri, strlen('entity:node/'));
      if ($nid > 0) {
        return $this->aliasManager->getAliasByPath('/node/' . $nid);
      }
    }

    // Fallback: return as-is (still useful for debugging).
    return $uri;
  }

  // ----------------------------
  // Params
  // ----------------------------

  private function boolParam(Request $request, string $name, bool $default = false): bool {
    $v = $request->query->get($name);
    if ($v === null || $v === '') return $default;
    return ((int) $v) === 1 || $v === 'true';
  }

  private function intParam(Request $request, string $name, ?int $default = null): int {
    $v = $request->query->get($name);
    if ($v === null || $v === '') {
      if ($default === null) throw new BadRequestHttpException("Missing param: {$name}");
      return $default;
    }
    if (!preg_match('/^-?\d+$/', (string) $v)) {
      throw new BadRequestHttpException("Invalid int param: {$name}");
    }
    return (int) $v;
  }

  private function intOptionalParam(Request $request, string $name, ?int $default = null): ?int {
    $v = $request->query->get($name);
    if ($v === null || $v === '') return $default;
    if (!preg_match('/^-?\d+$/', (string) $v)) {
      throw new BadRequestHttpException("Invalid int param: {$name}");
    }
    return (int) $v;
  }

  private function slugForNode(int $nid): string {
    $alias = $this->aliasManager->getAliasByPath("/node/{$nid}");
    $alias = trim($alias, '/');
    if ($alias === '') return (string) $nid;
    $parts = explode('/', $alias);
    return end($parts) ?: (string) $nid;
  }

  private function readStringField($entity, string $fieldName, string $default = ''): string {
    if (!$entity || !$entity->hasField($fieldName)) {
      return $default;
    }

    $items = $entity->get($fieldName);
    if ($items->isEmpty()) {
      return $default;
    }

    $value = trim((string) ($items->first()?->value ?? ''));
    return $value !== '' ? $value : $default;
  }

  private function readBoolFieldDefault($entity, string $fieldName, bool $default = false): bool {
    if (!$entity || !$entity->hasField($fieldName)) {
      return $default;
    }

    $items = $entity->get($fieldName);
    if ($items->isEmpty()) {
      return $default;
    }

    $value = (string) ($items->first()?->value ?? '');
    if ($value === '') {
      return $default;
    }

    return $value === '1';
  }

  private function pickBestObjectTile(array $tiles) {
    $best = null;
    $bestScore = -1;

    foreach ($tiles as $tile) {
      if (!$tile) continue;

      $displayMode = $this->readStringField($tile, 'field_tile_display_mode', 'cover');
      $canonical = $this->readBoolFieldDefault($tile, 'field_is_canonical', true);

      $score = 0;
      if ($canonical) $score += 100;
      if ($displayMode === 'song') $score += 50;
      if ($displayMode === 'newsletter') $score += 40;
      if ($displayMode === 'media_tile') $score += 45;
      if ($displayMode === 'video_tile') $score += 45;
      if ($displayMode === 'cover') $score += 10;

      if ($score > $bestScore) {
        $best = $tile;
        $bestScore = $score;
      }
    }

    return $best;
  }

  private function readBoolField($entity, string $fieldName, bool $default = false): bool {
    if (!$entity || !$entity->hasField($fieldName)) {
      return $default;
    }

    $items = $entity->get($fieldName);
    if ($items->isEmpty()) {
      return false;
    }

    $value = (string) ($items->first()?->value ?? '');
    return $value === '1';
  }


}
