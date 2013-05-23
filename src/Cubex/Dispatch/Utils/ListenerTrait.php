<?php
/**
 * @author: gareth.evans
 */
namespace Cubex\Dispatch\Utils;

use Cubex\Container\Container;
use Cubex\Core\Interfaces\INamespaceAware;
use Cubex\Dispatch\Dependency\Resource;
use Cubex\Dispatch\Dependency\Url;
use Cubex\Dispatch\DispatchEvent;
use Cubex\FileSystem\FileSystem;
use Cubex\Events\EventManager;

trait ListenerTrait
{
  protected function _listen($namespace = null)
  {
    if($namespace === null)
    {
      if($this instanceof INamespaceAware)
      {
        $namespace = $this->getNamespace();
      }
      else
      {
        $namespace = get_namespace($this);
      }
    }
    $fileSystem = new FileSystem();

    EventManager::listen(
      EventManager::DISPATCH_RESOURCE_REQUIRE,
      function (DispatchEvent $event) use ($namespace, $fileSystem)
      {
        $event->setNamespace($namespace);

        $resource = new Resource(
          Container::get(Container::CONFIG), $fileSystem
        );
        $resource->requireResource($event);
      },
      $namespace
    );

    EventManager::listen(
      EventManager::DISPATCH_PACKAGE_REQUIRE,
      function (DispatchEvent $event) use ($namespace, $fileSystem)
      {
        $event->setNamespace($namespace);

        $resource = new Resource(
          Container::get(Container::CONFIG), $fileSystem
        );
        $resource->requirePackage($event);
      },
      $namespace
    );

    EventManager::listen(
      EventManager::DISPATCH_BLOCK_ADD,
      function (DispatchEvent $event) use ($namespace, $fileSystem)
      {
        $event->setNamespace($namespace);

        $url = new Resource(Container::get(Container::CONFIG), $fileSystem);
        $url->addBlock($event);
      },
      $namespace
    );

    EventManager::listen(
      EventManager::DISPATCH_URL,
      function (DispatchEvent $event) use ($namespace, $fileSystem)
      {
        $event->setNamespace($namespace);

        $url = new Url(Container::get(Container::CONFIG), $fileSystem);

        return $url->getUri($event);
      },
      $namespace
    );
  }
}
