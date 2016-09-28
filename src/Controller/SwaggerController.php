<?php

namespace Drupal\waterwheel\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Routing\RouteProvider;
use Drupal\rest\Plugin\rest\resource\EntityResource;
use Drupal\rest\Plugin\Type\ResourcePluginManager;
use Drupal\rest\RestResourceConfigInterface;
use Drupal\schemata\SchemaFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Route;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Routes for Swagger json spec and Swagger UI.
 */
class SwaggerController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The plugin manager for REST plugins.
   *
   * @var \Drupal\rest\Plugin\Type\ResourcePluginManager
   */
  protected $manager;

  /**
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $fieldManager;

  /**
   * The Schemata SchemaFactory.
   *
   * @var \Drupal\schemata\SchemaFactory
   */
  protected $schemaFactory;

  /**
   * @var \Symfony\Component\Serializer\SerializerInterface
   */
  protected $serializer;

  /**
   * Constructs a new SwaggerController object.
   *
   * @param \Drupal\rest\Plugin\Type\ResourcePluginManager $manager
   *   The resource plugin manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $field_manager
   * @param \Drupal\schemata\SchemaFactory $schema_factory
   */
  public function __construct(ResourcePluginManager $manager, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $field_manager, SchemaFactory $schema_factory, Serializer $serializer) {

    $this->manager = $manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->fieldManager = $field_manager;
    $this->schemaFactory = $schema_factory;
    $this->serializer = $serializer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.rest'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('schemata.schema_factory'),
      $container->get('serializer')
    );
  }

  /**
   * Output Swagger compatible API spec.
   */
  public function swaggerAPI() {
    $spec = [
      'swagger' => "2.0",
      'schemes' => ['http'],
      'info' => $this->getInfo(),
      'paths' => $this->getPaths(),
      'host' => \Drupal::request()->getHost(),
      'basePath' => \Drupal::request()->getBasePath(),
      'definitions' => $this->getDefinitions(),
      'securityDefinitions' => $this->getSecurityDefinitions(),


    ];
    $response = new JsonResponse($spec);
    return $response;

  }

  /**
   * Creates the 'info' portion of the API.
   *
   * @return array
   *   The info elements.
   */
  protected function getInfo() {
    $site_name = $this->config('system.site')->get('name');
    return [
      'description' => '@todo update',
      'title' => $this->t('@site - API', ['@site' => $site_name]),
    ];
  }

  /**
   * Returns the paths information.
   *
   * @return array
   *   The info elements.
   */
  protected function getPaths() {
    $api_paths = [];
    /** @var \Drupal\rest\Entity\RestResourceConfig[] $resource_configs */
    $resource_configs = $this->entityTypeManager()
      ->getStorage('rest_resource_config')
      ->loadMultiple();

    foreach ($resource_configs as $id => $resource_config) {
      /** @var \Drupal\rest\Plugin\ResourceBase $plugin */
      $resource_plugin = $resource_config->getResourcePlugin();
      foreach ($resource_config->getMethods() as $method) {
        if ($route = $this->getRouteForResourceMethod($resource_config, $method)) {
          $swagger_method = strtolower($method);
          $path = $route->getPath();
          $path_method_spec = [];
          $formats = $resource_config->getFormats($method);
          $format_parameter = [
            'name' => '_format',
            'in' => 'query',
            'enum' => $formats,
            'required' => TRUE,
          ];
          if (count($formats) == 1) {
            $format_parameter['default'] = $formats[0];
          }
          $path_method_spec['parameters'][] = $format_parameter;
          if ($this->isEntityResource($resource_config)) {

            $entity_type = $this->entityTypeManager->getDefinition($resource_plugin->getPluginDefinition()['entity_type']);
            $path_method_spec['summary'] = $this->t('@method a @entity_type', [
              '@method' => ucfirst($swagger_method),
              '@entity_type' => $entity_type->getLabel(),
            ]);

            $path_method_spec['consumes'] = ['application/json'];
            $path_method_spec['produces'] = ['application/json'];
            $path_method_spec['parameters'] = array_merge($path_method_spec['parameters'], $this->getEntityParameters($entity_type, $method));

          }
          else {
            $path_method_spec['summary'] = $resource_plugin->getPluginDefinition()['label'];
            $path_method_spec['parameters'] = array_merge($path_method_spec['parameters'], $this->getRouteParameters($route));
          }

          $path_method_spec['operationId'] = $resource_plugin->getPluginId();
          $path_method_spec['schemes'] = ['http'];
          $path_method_spec['security'] = $this->getSecurity($resource_config, $method, $formats);
          $api_paths[$path][$swagger_method] = $path_method_spec;
        }
      }
    }
    return $api_paths;
  }

  /**
   * Get parameters for an entity type.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   * @param $method
   *
   * @return array
   */
  protected function getEntityParameters(EntityTypeInterface $entity_type, $method) {
    $parameters = [];
    if (in_array($method, ['GET', 'DELETE', 'PATCH'])) {
      $keys = $entity_type->getKeys();
      $parameters[] = [
        'name' => $entity_type->id(),
        'in' => 'path',
        'required' => TRUE,
        'default' => '',
        'description' => $this->t('The @id(id) of the @type.', [
          '@id' => $keys['id'],
          '@type' => $entity_type->id(),
        ]),
      ];
    }
    if (in_array($method, ['POST', 'PATCH'])) {
      $parameters[] = [
        'name' => 'body',
        'in' => 'body',
        'type' => 'body',
        'schema' => [
          '$ref' => '#/definitions/node'
        ],

      ];
      /*
      if ($entity_type instanceof ContentEntityTypeInterface) {
        $base_fields = $this->fieldManager->getBaseFieldDefinitions($entity_type->id());
        foreach ($base_fields as $field_name => $base_field) {
          $parameters[] = $this->getSwaggerFieldParameter($base_field);
        }
      }
      */
    }
    return $parameters;
  }

  /**
   * Gets the a Swagger parameter for a field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *
   * @return array
   */
  protected function getSwaggerFieldParameter(FieldDefinitionInterface $field) {
    $parameter = [
      'name' => $field->getName(),
      'required' => $field->isRequired(),
    ];
    $type = $field->getType();
    $date_types = ['changed', 'created'];
    if (in_array($type, $date_types)) {
      $parameter['type'] = 'string';
      $parameter['format'] = 'date-time';
    }
    else {
      $string_types = ['string_long', 'uuid'];
      if (in_array($type, $string_types)) {
        $parameter['type'] = 'string';
      }
    }
    $parameter['default'] = '';
    return $parameter;

  }

  /**
   * The Swagger UI page.
   *
   * @return array
   */
  public function swaggerUiPage() {
    $build = [
      '#theme' => 'swagger_ui',
      '#attached' => [
        'library' => [
          'waterwheel/swagger_ui_integration',
          'waterwheel/swagger_ui',
        ],
      ],
    ];

    return $build;
  }

  /**
   * Get Swagger parameters for a route.
   *
   * @param \Symfony\Component\Routing\Route $route
   *
   * @return array
   */
  protected function getRouteParameters(Route $route) {
    $parameters = [];
    $vars = $route->compile()->getPathVariables();
    foreach ($vars as $var) {
      $parameters[] = [
        'name' => $var,
        'type' => 'string',
        'in' => 'path',
        'default' => '',
        'required' => TRUE,
      ];
    }
    return $parameters;
  }

  /**
   * Gets the matching for route for the resource and method.
   *
   * @param $resource_config
   * @param $method
   *
   * @return \Symfony\Component\Routing\Route
   */
  protected function getRouteForResourceMethod(RestResourceConfigInterface $resource_config, $method) {
    if ($this->isEntityResource($resource_config)) {
      /** @var \Drupal\Core\Routing\RouteProvider $routing_provider */
      $routing_provider = \Drupal::service('router.route_provider');

      $route_name = 'rest.' . $resource_config->id() . ".$method";

      $routes = $routing_provider->getRoutesByNames([$route_name]);
      if (empty($routes)) {
        $formats = $resource_config->getFormats($method);
        if (count($formats) > 1) {
          $route_name .= ".{$formats[0]}";
          $routes = $routing_provider->getRoutesByNames([$route_name]);
        }
      }
      if ($routes) {
        return array_pop($routes);
      }
    }
    else {
      $resource_plugin = $resource_config->getResourcePlugin();
      foreach ($resource_plugin->routes() as $route) {
        $methods = $route->getMethods();
        if (array_search($method, $methods) !== FALSE) {
          return $route;
        }
      };
    }
    throw new \Exception("No route found for REST resource: " . $resource_config->id());
  }

  /**
   * Get the security information for the a resource.
   *
   * @see http://swagger.io/specification/#securityDefinitionsObject
   *
   * @param \Drupal\rest\RestResourceConfigInterface $resource_config
   * @param $method
   *
   * @return array
   */
  protected function getSecurity(RestResourceConfigInterface $resource_config, $method, $formats) {
    $security = [];
    foreach ($resource_config->getAuthenticationProviders($method) as $auth) {
      switch ($auth) {
        case 'basic_auth':
          $security[] = 'basic_auth';
      }
    }
    // @todo Handle tokens that need to be set in headers.

    if ($this->isEntityResource($resource_config)) {
      /** @var \Drupal\Core\Routing\RouteProvider $routing_provider */
      $routing_provider = \Drupal::service('router.route_provider');

      $route_name = 'rest.' . $resource_config->id() . ".$method";

      $routes = $routing_provider->getRoutesByNames([$route_name]);
      if (empty($routes) && count($formats) > 1) {
        $route_name .= ".{$formats[0]}";
        $routes = $routing_provider->getRoutesByNames([$route_name]);
      }
      if ($routes) {
        $route = array_pop($routes);
        // Check to see if route is protected by access checks in header.
        if ($route->getRequirement('_csrf_request_header_token')) {
          $security[] = 'csrf_token';
        }

      }
    }

    return $security;
  }

  private function getDefinitions() {
    $schema = $this->schemaFactory->create('node');
    $json_schema = $this->serializer->normalize($schema, 'json_schema');
    unset($json_schema['$schema'], $json_schema['id']);
    return [
      'node' => $json_schema,
    ];
  }

  /**
   * Determines if an REST resource is for an entity.
   *
   * @param $resource_config
   *
   * @return bool
   */
  protected function isEntityResource(RestResourceConfigInterface $resource_config) {
    $resource_plugin = $resource_config->getResourcePlugin();
    return $resource_plugin instanceof EntityResource;
  }

  protected function getSecurityDefinitions() {
    return [
      'csrf_token' => [
        'type' => 'apiKey',
        'name' => 'X-CSRF-Token',
        'in' => 'header',
      ],
      'basic_auth' => [
        'type' => 'basic',
      ],

    ];
  }

}
