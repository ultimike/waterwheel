<?php

namespace Drupal\waterwheel\Controller;


use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\rest\Plugin\rest\resource\EntityResource;
use Drupal\rest\Plugin\Type\ResourcePluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class SwaggerController extends ControllerBase implements ContainerInjectionInterface  {

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
   * Constructs a new SwaggerController object.
   *
   * @param \Drupal\rest\Plugin\Type\ResourcePluginManager $manager
   *   The resource plugin manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(ResourcePluginManager $manager, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $field_manager) {
    $this->manager = $manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->fieldManager = $field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.rest'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager')
    );
  }
  /**
   * Output Swagger compatible API spec.
   */
  public function swaggerAPI() {
    global $base_root;
    $spec = [
      'swagger' => "2.0",
      'schemes' => 'http',
      'info' => $this->getInfo(),
      'paths' => $this->getPaths(),
      'host' => \Drupal::request()->getHost(),
      'basePath' => \Drupal::request()->getBasePath(),

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
    return [
      'description' => '@todo update',
      'title' => '@todo update title',
    ];
  }

  /**
   * Creates the 'info' portion of the API.
   *
   * @return array
   *   The info elements.
   */
  protected function getPaths() {
    $api_paths = [];
    $resources = $this->manager->getDefinitions();
    /** @var \Drupal\rest\Entity\RestResourceConfig[] $resource_configs */
    $resource_configs = $this->entityTypeManager()->getStorage('rest_resource_config')->loadMultiple();

    foreach ($resource_configs as $id => $resource_config) {
      /** @var \Drupal\rest\Plugin\ResourceBase $plugin */
      $resource_plugin = $resource_config->getResourcePlugin();

      $routes = $resource_plugin->routes();
      /** @var \Symfony\Component\Routing\Route $route */
      foreach ($routes as $route) {


        /** @var \Drupal\Core\Routing\RouteProviderInterface $route_provider */
        $route_provider = \Drupal::service('router.route_provider');
        //$route_provider->getRouteByName('rest.' . $route-)
        $path = $route->getPath();
        $format = $route->getRequirement('_format');
        $methods = $route->getMethods();
        $a = 'b';
        //$plugin->
        foreach ($methods as $method) {
          $swagger_method = strtolower($method);
          $path_method_spec = [];

          if ($resource_plugin instanceof EntityResource) {

            $entity_type = $this->entityTypeManager->getDefinition($resource_plugin->getPluginDefinition()['entity_type']);
            $path_method_spec = [
              'summary' => $this->t('@method a @entity_type', ['@method' => ucfirst($swagger_method), '@entity_type' => $entity_type->getLabel()]),

            ];
            $path_method_spec['parameters'] = $this->getEntityParameters($entity_type, $method);

          }
          else {
            $path_method_spec = [
              'summary' => 'boo',
            ];
          }
          $path_method_spec['operationId'] = $resource_plugin->getPluginId();
          $api_paths[$path][$swagger_method] = $path_method_spec;

        }

      }
      //$routes = $resource->routes();


    }
    return $api_paths;
  }

  private function getEntityParameters(EntityTypeInterface $entity_type, $method) {
    $parameters = [];
    if ($entity_type instanceof ContentEntityTypeInterface) {
      $base_fields = $this->fieldManager->getBaseFieldDefinitions($entity_type->id());
      foreach ($base_fields as $field_name => $base_field) {
        $parameters[] = $this->getSwaggerParameter($base_field);
      }
    }
    return $parameters;


  }

  protected function getSwaggerParameter(FieldDefinitionInterface $field) {
    $parameter = [
      'name' => $field->getName(),
      'required' => $field->isRequired(),
    ];
    $type = $field->getType();
    $date_types = ['changed', 'created'];
    if (in_array($type, $date_types)) {
      $parameter['type'] = 'string';
      $parameter['format'] = 'date-time';
    } else {
      $string_types = ['string_long', 'uuid'];
      if (in_array($type, $string_types)) {
        $parameter['type'] = 'string';
      }
    }
    return $parameter;

  }

  public function swaggerUiPage() {
    $build = [
      '#theme' => 'swagger_ui',
      '#attached' => [
        'library' => ['waterwheel/swagger_ui_integration', 'waterwheel/swagger_ui'],
      ],
    ];

    return $build;

  }
}
