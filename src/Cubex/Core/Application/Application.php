<?php
/**
 * @author  brooke.bryan
 */
namespace Cubex\Core\Application;

use Cubex\Bundle\BundlerTrait;
use Cubex\Core\Controllers\BaseController;
use Cubex\Core\Interfaces\IDirectoryAware;
use Cubex\Core\Interfaces\INamespaceAware;
use Cubex\Core\Traits\NamespaceAwareTrait;
use Cubex\Data\Handler\IDataHandler;
use Cubex\Dispatch\Utils\ListenerTrait;
use Cubex\Events\IEvent;
use Cubex\Events\EventManager;
use Cubex\Foundation\Config\ConfigTrait;
use Cubex\Core\Http\IDispatchable;
use Cubex\Core\Http\IDispatchableAccess;
use Cubex\Core\Http\Request;
use Cubex\Core\Http\Response;
use Cubex\Core\Project\Project;
use Cubex\I18n\ITranslatable;
use Cubex\I18n\Translation;
use Cubex\I18n\ITranslatorAccess;
use Cubex\Routing\IRoute;
use Cubex\Routing\StdRoute;
use Cubex\Routing\StdRouter;
use Cubex\ServiceManager\IServiceManagerAware;
use Cubex\ServiceManager\ServiceManagerAwareTrait;
use Cubex\Theme\ApplicationTheme;

/**
 * Web Application
 */
abstract class Application
  implements IDispatchable, IDispatchableAccess,
             IDirectoryAware, ITranslatable, ITranslatorAccess,
             INamespaceAware, IServiceManagerAware
{
  use ConfigTrait;
  use Translation;
  use ListenerTrait;
  use ServiceManagerAwareTrait;
  use BundlerTrait;
  use NamespaceAwareTrait;

  /**
   * @var \Cubex\Core\Project\Project
   */
  protected $_project;
  protected $_layout = 'Default';

  protected $_baseUri;

  /**
   * @var \Cubex\Core\Http\Request
   */
  protected $_request;

  /**
   * @var \Cubex\Core\Http\Response
   */
  protected $_response;

  /**
   * Name of application
   *
   * @return string
   */
  public function name()
  {
    return "";
  }

  /**
   * Description of the application
   *
   * @return string
   */
  public function description()
  {
    return "";
  }

  protected function _configure()
  {
    return $this;
  }

  /**
   * @param \Cubex\Core\Project\Project $project
   */
  public function setProject(Project $project)
  {
    $this->_project = $project;
  }

  public function project()
  {
    if($this->_project === null)
    {
      throw new \Exception("Project not set");
    }
    else
    {
      return $this->_project;
    }
  }

  /**
   * Custom dispatch hook
   */
  public function dispatching()
  {
  }

  /**
   * @param \Cubex\Core\Http\Request  $request
   * @param \Cubex\Core\Http\Response $response
   *
   * @return \Cubex\Core\Http\Response
   * @throws \Exception
   */
  public function dispatch(Request $request, Response $response)
  {
    EventManager::trigger(
      EventManager::CUBEX_TIMETRACK_START,
      [
      'name'  => 'application.dispatch',
      'label' => "Dispatch Application"
      ]
    );

    $this->_request  = $request;
    $this->_response = $response;
    $this->_listen($this->getNamespace(), $this->getConfig());
    $this->addDefaultBundles();
    $this->initialiseBundles();
    $this->dispatching();

    $router = new StdRouter($this->_getRoutes(), $request->requestMethod());

    $dispatcherRoute = $router->getRoute($request->path());

    if($dispatcherRoute === null)
    {
      $dispatcherRoute = $this->defaultController();
    }

    if($dispatcherRoute === null)
    {
      throw new \Exception("No Controller or Dispatchable class available");
    }
    else if($dispatcherRoute instanceof IRoute)
    {
      $routeData        = $dispatcherRoute->routeData();
      $dispatcherResult = $dispatcherRoute->result();
    }
    else
    {
      $routeData        = [];
      $dispatcherResult = $dispatcherRoute;
    }

    $dispatcher = $action = null;

    if(is_scalar($dispatcherResult))
    {
      if(starts_with($dispatcherResult, '\\')
      && class_exists($dispatcherResult)
      )
      {
        $dispatcher = new $dispatcherResult;
      }
      else
      {
        $attempted = $this->_attemptClass($dispatcherResult);
        if($attempted !== null)
        {
          $dispatcher = new $attempted;
        }
      }

      if($dispatcher === null)
      {
        if(stristr($dispatcherResult, ','))
        {
          $dispatcherResult = explode(',', $dispatcherResult);
        }
        else if(stristr($dispatcherResult, '@'))
        {
          list($dispatcherResult, $action) = explode('@', $dispatcherResult);
          $attempted = $this->_attemptClass($dispatcherResult);
          if($attempted !== null)
          {
            $dispatcher = new $attempted;
          }
        }
      }
    }

    if(empty($dispatcherResult))
    {
      $dispatcherResult = $this->defaultController();
    }

    if(is_callable($dispatcherResult))
    {
      $dispatcher = $dispatcherResult();
    }
    else if($dispatcher === null)
    {
      $dispatcher = $dispatcherResult;
    }

    if($dispatcher instanceof IDispatchable)
    {
      if($dispatcher instanceof BaseController)
      {
        $dispatcher->forceAction($action);
      }

      if($dispatcher instanceof IDataHandler)
      {
        $dispatcher->hydrate($routeData);
      }

      if($dispatcher instanceof IController)
      {
        $dispatcher->setBaseUri($this->baseUri());
        $matchRoute = $router->getMatchedRoute();
        if($matchRoute !== null)
        {
          $matchedUri = $matchRoute->pattern(true);
          $pattern    = StdRouter::convertSimpleRoute($matchedUri);
          $matches    = [];
          $match      = preg_match("#$pattern#", $request->path(), $matches);
          $dispatcher->setBaseUri($request->path(substr_count($pattern, '/')));

          if($match)
          {
            $dispatcher->setBaseUri($matches[0]);
          }
          else
          {
            $dispatcher->setBaseUri(
              $request->path(substr_count($pattern, '/'))
            );
          }
        }
        $dispatcher->setApplication($this);
      }

      if($dispatcher instanceof IServiceManagerAware)
      {
        $dispatcher->setServiceManager($this->getServiceManager());
      }

      $dispatcher->configure($this->_configuration);
      $response = $dispatcher->dispatch($request, $response);
    }
    else
    {
      throw new \Exception(
        "Invalid dispatcher defined " . json_encode($dispatcher)
      );
    }

    $this->shutdownBundles();

    EventManager::trigger(
      EventManager::CUBEX_TIMETRACK_END,
      [
      'name' => 'application.dispatch'
      ]
    );

    return $response;
  }

  protected function _attemptClass($dispatcherResult)
  {
    $namespaces = $this->config('project')->getArr('controller_namespaces');
    if(!$namespaces)
    {
      $namespaces = [$this->getNamespace()];
    }

    $try = [];
    foreach($namespaces as $ns)
    {
      $try[] = $ns . '\Controllers\\' . $dispatcherResult;
      $try[] = $ns . '\\' . $dispatcherResult;
      $try[] = $ns . '\Controllers\\' . $dispatcherResult . "Controller";
      $try[] = $ns . '\\' . $dispatcherResult . "Controller";
    }

    foreach($try as $controller)
    {
      if(class_exists($controller))
      {
        return $controller;
      }
    }
    return null;
  }

  public function baseUri()
  {
    return $this->_baseUri;
  }

  public function setBaseUri($uri)
  {
    $this->_baseUri = $uri;
    return $this;
  }

  /**
   * @return \Cubex\Core\Http\Request
   */
  public function request()
  {
    return $this->_request;
  }

  /**
   * @return \Cubex\Core\Http\Response
   */
  public function response()
  {
    return $this->_response;
  }

  /**
   * @return null|\Cubex\Core\Http\IDispatchable
   */
  public function defaultDispatcher()
  {
    return null;
  }

  /**
   * @return null|\Cubex\Core\Http\IDispatchable
   */
  public function defaultController()
  {
    return $this->defaultDispatcher();
  }

  /**
   * @return IRoute[]
   */
  protected function _getRoutes()
  {
    $interalRoutes = $this->getRoutes();
    $bundleRoutes  = $this->getAllBundleRoutes();
    $routes        = array_merge((array)$interalRoutes, (array)$bundleRoutes);

    if(!empty($routes) && $this->_baseUri !== null)
    {
      $routes = array($this->_baseUri => $routes);
    }

    return StdRoute::fromArray($routes);
  }

  /**
   * @return array|IRoute[]
   */
  public function getRoutes()
  {
    return [];
  }

  /**
   * @return string
   */
  public function layout()
  {
    return $this->_layout;
  }

  /**
   * @param $layout
   *
   * @return $this
   */
  public function setLayout($layout)
  {
    $this->_layout = $layout;
    return $this;
  }

  /**
   * Returns the directory of the class
   *
   * @return string
   */
  public function containingDirectory()
  {
    $class     = get_called_class();
    $reflector = new \ReflectionClass($class);
    return dirname($reflector->getFileName());
  }

  public function init()
  {
    $this->_registerI18nListeners();
    $this->_configure();
  }

  protected function _registerI18nListeners($namespace = null)
  {
    if($namespace == null)
    {
      $namespace = $this->getNamespace();
    }

    EventManager::listen(
      EventManager::CUBEX_TRANSLATE_T,
      function (IEvent $e)
      {
        return call_user_func([$this, 't'], $e->getStr("text"));
      },
      $namespace
    );

    EventManager::listen(
      EventManager::CUBEX_TRANSLATE_P,
      function (IEvent $e)
      {
        $args = [
          $e->getStr("singular"),
          $e->getStr("plural"),
          $e->getInt("number"),
        ];

        return call_user_func_array([$this, 'p'], $args);
      },
      $namespace
    );
  }

  public function projectBase()
  {
    return $this->getConfig()->get("_cubex_")->getStr('project_base');
  }

  public function getTheme()
  {
    return new ApplicationTheme($this);
  }
}
